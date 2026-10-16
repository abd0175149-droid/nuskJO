<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * ما يحدث بعد خطأ ميتا مصنَّف (E18): عدّادات الفئات، وwa_last_error للقناة، والتنبيه المحدود، والإيقاف الآلي.
 * كله داخل try/catch: التنبيه لا يُفشل إرسالاً ولا ويبهوك. ولا شيء للنموذج ولا لملاحظات العميل.
 *
 * وضع الظل (meta_error_v2 مطفأ): العدّادات وwa_last_error فقط، والتنبيه والإيقاف على المسار القديم في مواضعه.
 */
final class MetaErrorReactor
{
    /** تكرار 130429 (خنق القناة) خلال 24 ساعة قبل أن ينبّه */
    private const THROTTLE_ALERT_AFTER = 3;

    /** الرمز المجهول ينبّه مرة في اليوم إن تكرر هذا العدد */
    private const UNKNOWN_ALERT_AFTER = 3;

    /** @param  array{conv_id?: ?int, message_id?: ?int, wamid?: ?string, source?: ?string}  $ctx */
    public static function react(MetaError $e, array $ctx = []): void
    {
        try {
            self::handle($e, $ctx);
        } catch (\Throwable $x) {
            WaLog::error('meta.reactor_failed', ['type' => class_basename($x), 'code' => $e->code]);
        }
    }

    private static function handle(MetaError $e, array $ctx): void
    {
        // ميتا تعيد ويبهوك الحالة نفسها: لا عدّ ولا تنبيه مرتين لرسالة واحدة
        if (!empty($ctx['wamid']) && !Cache::add('wa:meta:seen:'.sha1($ctx['wamid'].'|'.$e->code), 1, now()->addDays(2))) {
            return;
        }

        $day = now()->format('Ymd');
        self::increment("wa:meta:cat:{$e->category}:{$day}");
        $unknownCount = 0;
        if ($e->category === 'unknown' && $e->metaCode !== null) {
            $unknownCount = self::increment("wa:meta:unknown:{$e->metaCode}:{$day}");
            // قائمة رموز اليوم المجهولة لـ whatsapp:doctor (المخزن لا يعدّد مفاتيحه)
            $codes = (array) Cache::get("wa:meta:unknown:codes:{$day}", []);
            if (!in_array($e->metaCode, $codes, true) && count($codes) < 50) {
                Cache::put("wa:meta:unknown:codes:{$day}", [...$codes, $e->metaCode], now()->addDays(10));
            }
        }
        MetaErrorCatalog::rememberAccountError($e->metaCode);
        $last = self::touchesChannel($e) ? self::recordLastError($e, $ctx) : null;

        if (!MetaErrorCatalog::v2()) {
            return;
        }

        // الإيقاف: منطق E06 نفسه (368 وحده افتراضياً، A10)، وكل إيقاف تدقيق آمن وتنبيه. ولا رفع آلياً أبداً
        if ($e->metaCode !== null && MetaErrorCatalog::autosuspends($e->metaCode)) {
            MetaErrorCatalog::suspend($e->metaCode, 'meta_event');
        }

        $alert = $e->alert
            || ($e->metaCode === 130429 && ($last['count_24h'] ?? 0) >= self::THROTTLE_ALERT_AFTER)
            || ($unknownCount >= self::UNKNOWN_ALERT_AFTER && Cache::add("wa:meta:unknown:alert:{$e->metaCode}:{$day}", 1, now()->addDay()));
        if ($alert) {
            // مرة في الساعة لكل رمز (OpsAlerts). الرمز والوصف والإجراء وfbtrace_id، بلا رقم عميل ولا نص
            $ref = $e->metaCode !== null ? "{$e->code} · {$e->metaCode}" : $e->code;
            $body = "{$e->staffMessage} {$e->action} [{$ref}]".($e->fbtraceId ? " fbtrace: {$e->fbtraceId}" : '');
            app(OpsAlerts::class)->notifyAdmins('meta:'.($e->metaCode ?? $e->code), MetaErrorCatalog::alertTitle($e->metaCode, $e->category), $body, '/whatsapp/settings#ops');
        }
    }

    /** خطأ على مستوى القناة من ميتا (ومن الخنق 130429 وحده). أخطاء العميل الواحد لا تمسه */
    private static function touchesChannel(MetaError $e): bool
    {
        return $e->metaCode !== null && (($e->isChannelWide() && $e->category !== 'throttle') || $e->metaCode === 130429);
    }

    /** تحديث عمود واحد مباشرة، خارج SettingsWriter، فلا يرفع نسخة الإعدادات ولا يصطدم بحفظ متزامن */
    private static function recordLastError(MetaError $e, array $ctx): array
    {
        $s = WaBotSetting::current();
        $prev = (array) ($s->wa_last_error ?? []);
        $recent = isset($prev['at']) && now()->diffInSeconds(Carbon::parse($prev['at']), true) < 86400;
        $same = $recent && ($prev['meta_code'] ?? null) === $e->metaCode && ($prev['error_code'] ?? null) === $e->code;

        $last = [
            'meta_code' => $e->metaCode,
            'error_code' => $e->code,
            'category' => $e->category,
            'message' => $e->staffMessage,
            'action' => $e->action,
            'fbtrace_id' => $e->fbtraceId,
            'at' => now()->toIso8601String(),
            'source' => isset($ctx['source']) ? mb_substr((string) $ctx['source'], 0, 16) : null,
            'count_24h' => $same ? (int) ($prev['count_24h'] ?? 0) + 1 : 1,
        ];
        WaBotSetting::whereKey($s->getKey())->update(['wa_last_error' => json_encode($last, JSON_UNESCAPED_UNICODE)]);

        return $last;
    }

    private static function increment(string $key): int
    {
        Cache::add($key, 0, now()->addDays(10));

        return (int) Cache::increment($key);
    }

    /** رموز اليوم المجهولة وعدد كل منها (whatsapp:doctor) */
    public static function unknownCodes(?string $day = null): array
    {
        $day ??= now()->format('Ymd');
        $out = [];
        foreach ((array) Cache::get("wa:meta:unknown:codes:{$day}", []) as $code) {
            $out[(int) $code] = (int) Cache::get("wa:meta:unknown:{$code}:{$day}", 0);
        }

        return $out;
    }

    /** عدّادات الفئات لليوم (بطاقة الإعدادات وwhatsapp:doctor) */
    public static function categoryCounts(?string $day = null): array
    {
        $day ??= now()->format('Ymd');
        $out = [];
        foreach (array_keys(MetaErrorCatalog::CATEGORIES) as $category) {
            $out[$category] = (int) Cache::get("wa:meta:cat:{$category}:{$day}", 0);
        }

        return $out;
    }
}
