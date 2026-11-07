<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\WaLog;
use Illuminate\Support\Facades\DB;

/**
 * تنظيف مرة واحدة (2026-11-07) لما خزّنه الربط الأول قبل أن يعرف المحلل rawType: البوابة سمّت كل نوع لا تعرفه
 * «unknown»، فصار إشعار التشفير وتغيير رمز الأمان لكل محادثة «⚠️ رسالة لا يعرضها واتساب ويب»، وفتح محادثات بلا
 * محتوى، ورفع غير المقروء، وعلّمها «تحتاج تدخّلاً» (unsupported).
 *
 * - unknown بلا نص ولا وسيط: إشعار داخلي، يُحذف.
 * - unknown بنص: نص عادي (عبر Eloquent فيتحدّث نص البحث وفهرسه).
 * - المحادثة التي لم يبقَ فيها شيء ولا عميل لها تُحذف، والباقية تُصحَّح معاينتها ووقتها وغير المقروء و«التدخّل».
 * آمن للإعادة: لا يجد شيئاً في المرة الثانية.
 *
 * @return array{deleted: int, converted: int, conversations_removed: int, conversations_fixed: int}
 */
final class UnknownNoticeCleanup
{
    public static function run(): array
    {
        $unknown = fn () => WaMessage::query()->where('msg_type', 'unsupported')
            ->whereRaw("json_extract(payload, '$.type') = 'unknown'")
            ->whereRaw("json_extract(payload, '$.rawType') IS NULL");
        $empty = "trim(coalesce(json_extract(payload, '$.body'), '')) = ''";

        $noise = $unknown()->whereRaw($empty)->whereNull('media_id')->get(['id', 'conversation_id', 'direction']);
        $affected = $noise->pluck('conversation_id')->unique()->filter()->values();
        $inbound = $noise->where('direction', 'in')->countBy('conversation_id');
        $deleted = 0;
        foreach ($noise->pluck('id')->chunk(500) as $ids) {
            $deleted += WaMessage::whereIn('id', $ids->all())->delete();
        }

        $converted = 0;
        $unknown()->whereRaw("NOT ({$empty})")->orderBy('id')->each(function (WaMessage $m) use (&$converted) {
            $m->msg_type = 'text';
            $m->body = (string) data_get($m->payload, 'body', '');
            $m->save();
            $converted++;
        });

        [$removed, $fixed] = [0, 0];
        foreach ($affected as $convId) {
            $conv = WaConversation::find($convId);
            if ($conv === null) {
                continue;
            }
            $newest = WaMessage::where('conversation_id', $conv->id)->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'body', 'created_at']);
            if ($newest === null && $conv->client_id === null) {
                DB::transaction(function () use ($conv) {
                    foreach (['wa_conversation_topics', 'wa_conversation_labels'] as $t) {
                        if (\Illuminate\Support\Facades\Schema::hasTable($t)) {
                            DB::table($t)->where('conversation_id', $conv->id)->delete();
                        }
                    }
                    $conv->delete();
                });
                $removed++;

                continue;
            }
            $conv->forceFill(array_filter([
                'last_message_at' => $newest?->created_at,
                'last_message_preview' => $newest ? mb_substr(trim((string) $newest->body), 0, 160) : null,
            ], fn ($v) => $v !== null) + [
                'unread_count' => max(0, (int) $conv->unread_count - (int) ($inbound[$conv->id] ?? 0)),
            ] + ($conv->attention_reason === 'unsupported' ? ['needs_attention' => false, 'attention_reason' => null] : []))->save();
            $fixed++;
        }

        $out = ['deleted' => $deleted, 'converted' => $converted, 'conversations_removed' => $removed, 'conversations_fixed' => $fixed];
        WaLog::info('openwa.unknown_cleanup', $out);

        return $out;
    }
}
