<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaLabel;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * تصنيفات سير العمل على المحادثات (E29، C127): قائمة يديرها المدير، ويختار منها من يرد على المحادثة بلا نص حر،
 * بحد WaLabel::MAX_PER_CONVERSATION. تصنيفات تطبيق WhatsApp Business لا endpoint لها في Cloud API، فهي داخلية بالكامل.
 *
 * التصنيفات لا تمس الرؤية ولا التوكيل: هذه الخدمة لا تستدعي موجّه المواضيع ولا تكتب assigned_to ولا جدول المواضيع
 * (LabelsDoNotAffectVisibilityTest يحرس ذلك نصاً وسلوكاً). ولا يقرؤها البوت ولا تصل مزوّد الذكاء.
 */
final class LabelService
{
    /** الجداول موجودة (بين نشر الكود والهجرة لا تكون، والعلم مطفأ أصلاً) */
    public static function available(): bool
    {
        return once(fn () => Schema::hasTable('wa_labels') && Schema::hasTable('wa_conversation_labels'));
    }

    public static function enabled(): bool
    {
        return FeatureFlags::on('wa_labels') && self::available();
    }

    /** القائمة الفعّالة لمنتقي الصندوق وفلتره */
    public static function activeList(): array
    {
        return self::available()
            ? WaLabel::active()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'color'])->map(fn (WaLabel $l) => $l->only(['id', 'name', 'color']))->all()
            : [];
    }

    /**
     * شرائح محادثات الصفحة كلها باستعلام واحد (لا N+1). المعطّل يبقى ظاهراً بلون باهت (active=false).
     *
     * @param  list<int>  $conversationIds
     * @return array<int, list<array{id: int, name: string, color: string, active: bool}>>
     */
    public static function chipsFor(array $conversationIds): array
    {
        if ($conversationIds === [] || !self::available()) {
            return [];
        }
        $out = [];
        DB::table('wa_conversation_labels')
            ->join('wa_labels', 'wa_labels.id', '=', 'wa_conversation_labels.label_id')
            ->whereIn('wa_conversation_labels.conversation_id', $conversationIds)
            ->orderBy('wa_labels.sort_order')->orderBy('wa_labels.id')
            ->get(['wa_conversation_labels.conversation_id', 'wa_labels.id', 'wa_labels.name', 'wa_labels.color', 'wa_labels.is_active'])
            ->each(function ($row) use (&$out) {
                $out[(int) $row->conversation_id][] = ['id' => (int) $row->id, 'name' => $row->name, 'color' => $row->color, 'active' => (bool) $row->is_active];
            });

        return $out;
    }

    /** @return list<array{id: int, name: string, color: string, active: bool}> */
    public static function attach(WaConversation $c, WaLabel $l, User $u): array
    {
        if (!$l->is_active) {
            throw ValidationException::withMessages(['label' => 'هذا التصنيف معطّل — اختر تصنيفاً فعّالاً']);
        }

        $inserted = DB::transaction(function () use ($c, $l, $u) {
            $rows = DB::table('wa_conversation_labels')->where('conversation_id', $c->id);
            if ((clone $rows)->where('label_id', $l->id)->exists()) {
                return 0;
            }
            if ((clone $rows)->count() >= WaLabel::MAX_PER_CONVERSATION) {
                throw ValidationException::withMessages(['label' => 'الحد '.WaLabel::MAX_PER_CONVERSATION.' تصنيفات للمحادثة — أزل تصنيفاً أولاً']);
            }

            return DB::table('wa_conversation_labels')->insertOrIgnore([
                'conversation_id' => $c->id, 'label_id' => $l->id, 'created_by' => $u->id, 'created_at' => now(),
            ]);
        });
        if ($inserted > 0) {
            AuditLog::safe(AuditAction::WaLabelAttach, 'wa_conversation', $c->id, null, ['label_id' => $l->id]);
        }

        return self::chipsFor([$c->id])[$c->id] ?? [];
    }

    /** @return list<array{id: int, name: string, color: string, active: bool}> */
    public static function detach(WaConversation $c, WaLabel $l, User $u): array
    {
        $deleted = DB::table('wa_conversation_labels')->where('conversation_id', $c->id)->where('label_id', $l->id)->delete();
        if ($deleted > 0) {
            AuditLog::safe(AuditAction::WaLabelDetach, 'wa_conversation', $c->id, null, ['label_id' => $l->id]);
        }

        return self::chipsFor([$c->id])[$c->id] ?? [];
    }

    /**
     * اسم التصنيف كما يُحفظ: بلا محارف تحكم ولا اتجاه، بين 2 و30 حرفاً. يُرفض ما يشبه رقماً شخصياً (5 أرقام متتالية فأكثر
     * بعد توحيد الأرقام، ولو فرّقتها مسافات أو شرطات) أو بريداً أو رابطاً: التصنيف ليس مكاناً لجواز أو هاتف.
     */
    public static function validateName(mixed $name): string
    {
        $clean = TextNormalizer::untrustedName(is_string($name) ? $name : '', 200) ?? '';
        $len = mb_strlen($clean);
        $error = match (true) {
            $len < 2 || $len > 30 => 'اسم التصنيف بين 2 و30 حرفاً',
            (bool) preg_match('/\d(?:[\s\-.]?\d){4,}/u', NumberGuard::latinDigits($clean)) => 'لا أرقام طويلة في اسم التصنيف (هاتف أو جواز أو هوية)',
            str_contains($clean, '@') || str_contains($clean, '＠') => 'لا بريد في اسم التصنيف',
            (bool) preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|net|org|info|io|me|co|jo|sa|ae|link|ly)\b)~i', $clean) => 'لا روابط في اسم التصنيف',
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['name' => $error]);
        }

        return $clean;
    }

    /** التعطيل يُبقي الوسم على المحادثات القديمة بلون باهت، ولا يُختار من جديد */
    public static function deactivate(WaLabel $l): void
    {
        $l->update(['is_active' => false]);
    }

    /**
     * الحذف للتصنيف غير المستعمل، أو مع «إزالة من كل المحادثات» (detach_all) في معاملة واحدة، لأنه لا FK.
     *
     * @return int عدد المحادثات التي أُزيل عنها
     */
    public static function destroy(WaLabel $l, bool $detachAll): int
    {
        $used = DB::table('wa_conversation_labels')->where('label_id', $l->id)->count();
        if ($used > 0 && !$detachAll) {
            throw ValidationException::withMessages(['label' => "التصنيف مستعمل في {$used} محادثة — عطّله بدل حذفه، أو اختر «إزالة من كل المحادثات»"]);
        }
        DB::transaction(function () use ($l) {
            DB::table('wa_conversation_labels')->where('label_id', $l->id)->delete();
            $l->delete();
        });

        return $used;
    }

    /** دمج محادثتين لعميل واحد (E11): وسوم الأولى تنتقل إلى الباقية بلا تكرار، داخل معاملة الدمج */
    public static function moveConversation(int $fromId, int $toId): void
    {
        if (!self::available()) {
            return;
        }
        foreach (DB::table('wa_conversation_labels')->where('conversation_id', $fromId)->get() as $row) {
            DB::table('wa_conversation_labels')->insertOrIgnore([
                'conversation_id' => $toId, 'label_id' => $row->label_id, 'created_by' => $row->created_by, 'created_at' => $row->created_at,
            ]);
        }
        DB::table('wa_conversation_labels')->where('conversation_id', $fromId)->delete();
    }
}
