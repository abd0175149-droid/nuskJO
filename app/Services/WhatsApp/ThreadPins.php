<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaThreadPin;
use App\Support\FeatureFlags;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * الملاحظة المثبتة والرسائل المثبتة داخل الخيط (E29، C128). قائمة الأنواع في /messages لا تتضمن تثبيتاً، فالتثبيت عندنا
 * وحدنا ولا يراه العميل. جدول مستقل لا يقرؤه البوت (لا wa_customer_notes، فـ customerCard لا يحقنها في سياق النموذج).
 * أي من يرد على المحادثة يثبّت ويعدّل، وكل تعديل يُسجَّل بمن فعله، ونص الملاحظة لا يدخل سجل التدقيق.
 */
final class ThreadPins
{
    /** أنواع لا تُثبَّت: التفاعل ليس رسالة، ورسالة النظام تحديث هوية من واتساب */
    private const UNPINNABLE = ['reaction', 'system'];

    public static function available(): bool
    {
        return once(fn () => Schema::hasTable('wa_thread_pins'));
    }

    public static function enabled(): bool
    {
        return FeatureFlags::on('thread_pins') && self::available();
    }

    /**
     * الحفظ: نص بلا محارف تحكم ولا اتجاه (سطر واحد)، حتى 500 حرف، ولا ما يشبه جوازاً أو هوية أو بطاقة.
     * ملاحظة واحدة لكل محادثة (الفهرس الجزئي wa_thread_pins_one_note يفرضها حتى مع طلبين متزامنين).
     */
    public static function saveNote(WaConversation $c, mixed $note, User $u): void
    {
        // السطر الجديد مسافة قبل التنقية (untrustedName يحذف محارف التحكم فيلصق الكلمتين)
        $clean = TextNormalizer::untrustedName(is_string($note) ? (preg_replace('/[\r\n\t\x{2028}\x{2029}]+/u', ' ', $note) ?? '') : '', 2000) ?? '';
        if ($clean === '') {
            throw ValidationException::withMessages(['note' => 'اكتب الملاحظة أولاً']);
        }
        if (mb_strlen($clean) > WaThreadPin::MAX_NOTE) {
            throw ValidationException::withMessages(['note' => 'الملاحظة المثبتة حتى '.WaThreadPin::MAX_NOTE.' حرف']);
        }
        if (NoteGuard::looksSensitive($clean)) {
            throw ValidationException::withMessages(['note' => NoteGuard::MESSAGE]);
        }

        $write = fn () => DB::transaction(function () use ($c, $clean, $u) {
            $pin = WaThreadPin::where('conversation_id', $c->id)->where('kind', 'note')->first();
            if ($pin) {
                $pin->update(['note' => $clean, 'updated_by' => $u->id]);
            } else {
                WaThreadPin::create(['conversation_id' => $c->id, 'kind' => 'note', 'note' => $clean, 'created_by' => $u->id, 'updated_by' => $u->id]);
            }
        });
        try {
            $write();
        } catch (UniqueConstraintViolationException) {
            // كتبها غيرك في اللحظة نفسها: تُحدَّث ملاحظته بنصك
            $write();
        }
        AuditLog::safe(AuditAction::WaPinSet, 'wa_conversation', $c->id, null, ['kind' => 'note', 'len' => mb_strlen($clean)]);
    }

    public static function clearNote(WaConversation $c, User $u): void
    {
        if (WaThreadPin::where('conversation_id', $c->id)->where('kind', 'note')->delete() > 0) {
            AuditLog::safe(AuditAction::WaPinClear, 'wa_conversation', $c->id, null, ['kind' => 'note']);
        }
    }

    public static function pinMessage(WaConversation $c, WaMessage $m, User $u): void
    {
        if (in_array($m->msg_type, self::UNPINNABLE, true) || $m->source === 'system') {
            throw ValidationException::withMessages(['message' => 'لا تُثبَّت التفاعلات ولا رسائل النظام']);
        }
        $inserted = DB::transaction(function () use ($c, $m, $u) {
            $pins = WaThreadPin::where('conversation_id', $c->id)->where('kind', 'message');
            if ((clone $pins)->where('message_id', $m->id)->exists()) {
                return 0;
            }
            if ((clone $pins)->count() >= WaThreadPin::MAX_MESSAGES) {
                throw ValidationException::withMessages(['message' => 'ألغِ تثبيت رسالة أولاً: الحد '.WaThreadPin::MAX_MESSAGES.' رسائل مثبتة']);
            }

            return DB::table('wa_thread_pins')->insertOrIgnore([
                'conversation_id' => $c->id, 'kind' => 'message', 'message_id' => $m->id,
                'created_by' => $u->id, 'updated_by' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        if ($inserted > 0) {
            AuditLog::safe(AuditAction::WaPinSet, 'wa_conversation', $c->id, null, ['kind' => 'message', 'message_id' => $m->id]);
        }
    }

    public static function unpinMessage(WaConversation $c, WaMessage $m, User $u): void
    {
        if (WaThreadPin::where('conversation_id', $c->id)->where('kind', 'message')->where('message_id', $m->id)->delete() > 0) {
            AuditLog::safe(AuditAction::WaPinClear, 'wa_conversation', $c->id, null, ['kind' => 'message', 'message_id' => $m->id]);
        }
    }

    /**
     * ما يُعرض في رأس المحادثة: الملاحظة بمن عدّلها ومتى، والرسائل المثبتة بمقتطف 140 حرفاً.
     *
     * @return array{note: ?array{text: string, by: ?string, at: ?string}, messages: list<array{id: int, excerpt: string, at: ?string, direction: string}>}
     */
    public static function summary(WaConversation $c): array
    {
        if (!self::available()) {
            return ['note' => null, 'messages' => []];
        }
        $pins = WaThreadPin::with(['editor:id,name', 'message:id,conversation_id,direction,body,created_at'])
            ->where('conversation_id', $c->id)->orderBy('id')->get();
        $note = $pins->firstWhere('kind', 'note');

        return [
            'note' => $note ? ['text' => (string) $note->note, 'by' => $note->editor?->name, 'at' => $note->updated_at?->format('Y-m-d H:i')] : null,
            'messages' => $pins->where('kind', 'message')
                // رسالة حُذفت أو نُقلت لمحادثة أخرى لا تظهر هنا
                ->filter(fn (WaThreadPin $p) => $p->message && (int) $p->message->conversation_id === (int) $c->id)
                ->map(fn (WaThreadPin $p) => [
                    'id' => (int) $p->message_id,
                    'excerpt' => mb_substr(trim((string) $p->message->body), 0, 140),
                    'at' => $p->message->created_at?->format('Y-m-d H:i'),
                    'direction' => (string) $p->message->direction,
                ])->values()->all(),
        ];
    }

    /**
     * دمج محادثتين لعميل واحد (E11): الرسائل انتقلت فتنتقل تثبيتاتها، والملاحظتان تُدمجان بفاصل «—» حتى 500 حرف.
     * يُستدعى داخل معاملة الدمج.
     */
    public static function moveConversation(int $fromId, int $toId): void
    {
        if (!self::available()) {
            return;
        }
        foreach (DB::table('wa_thread_pins')->where('conversation_id', $fromId)->where('kind', 'message')->get() as $row) {
            DB::table('wa_thread_pins')->insertOrIgnore(['conversation_id' => $toId] + array_diff_key((array) $row, ['id' => 0, 'conversation_id' => 0]));
        }
        $from = DB::table('wa_thread_pins')->where('conversation_id', $fromId)->where('kind', 'note')->first();
        if ($from) {
            $to = DB::table('wa_thread_pins')->where('conversation_id', $toId)->where('kind', 'note')->first();
            if ($to) {
                DB::table('wa_thread_pins')->where('id', $to->id)->update([
                    'note' => mb_substr(trim($to->note.' — '.$from->note), 0, WaThreadPin::MAX_NOTE), 'updated_at' => now(),
                ]);
            } else {
                DB::table('wa_thread_pins')->insert(['conversation_id' => $toId] + array_diff_key((array) $from, ['id' => 0, 'conversation_id' => 0]));
            }
        }
        DB::table('wa_thread_pins')->where('conversation_id', $fromId)->delete();
    }
}
