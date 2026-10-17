<?php

namespace App\Services\Ops;

use App\Models\WaMessage;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صمت الوارد في وقت الدوام (E37، بديل وصفات n8n C121): الفحص الوحيد الناقص بين تنبيهات التشغيل. يكشف أن ميتا لم تعد
 * ترسل لنا شيئاً (رابط الويبهوك في لوحة التطبيق وُجّه إلى نظام آخر مثلاً) فيصمت الصندوق بلا أي خطأ.
 * المصدر: آخر حدث في wa_webhook_events (E20، والحالات تُحسب)، واحتياطاً آخر وارد في wa_messages. لا يدخل /up:
 * صمت ميتا لا يجوز أن يُسقط الحاوية. والتنبيه بلا رقم ولا نص عميل، مرة كل 6 ساعات، وفي أسبوع هادئ لا تنبيه.
 */
final class InboundSilence
{
    /** أيام البحث عن حركة في الساعة نفسها قبل التنبيه */
    public const LOOKBACK_DAYS = 14;

    public const REALERT_HOURS = 6;

    private const ALERTED_KEY = 'ops:inbound_silence:alerted';

    public static function lastInboundAt(): ?Carbon
    {
        $times = [];
        try {
            if (Schema::hasTable('wa_webhook_events')) {
                $times[] = DB::table('wa_webhook_events')->max('received_at');
            }
        } catch (\Throwable) {
            // الاحتياط أدناه يكفي
        }
        try {
            // أحدث وارد بالمفتاح الأساسي نزولاً: يقف عند أول صف بدل مسح الفهرس كله
            $times[] = DB::table('wa_messages')->where('direction', 'in')->orderByDesc('id')->value('created_at');
        } catch (\Throwable) {
            return null;
        }
        $times = array_values(array_filter($times));
        // نص القاعدة بتوقيت التطبيق صراحةً
        $tz = (string) config('app.timezone');

        return $times ? max(array_map(fn ($t) => Carbon::parse((string) $t, $tz), $times)) : null;
    }

    /** دقائق منذ آخر حدث، أو null: لم يصل شيء بعد */
    public static function minutesSinceLast(?Carbon $now = null): ?int
    {
        $last = self::lastInboundAt();

        return $last ? (int) floor($last->diffInMinutes($now ?? now(), true)) : null;
    }

    /** الدوام المنظّم من config('ops.business_hours')؛ القيمة الحرة company_hours لا تصلح للحساب */
    public static function inBusinessHours(?Carbon $at = null): bool
    {
        $cfg = (array) config('ops.business_hours');
        $local = ($at ?? now())->copy()->setTimezone($cfg['timezone'] ?? 'Asia/Amman');
        if (!in_array($local->dayOfWeek, (array) ($cfg['days'] ?? []), true)) {
            return false;
        }
        $hm = $local->format('H:i');

        return $hm >= ($cfg['from'] ?? '09:00') && $hm < ($cfg['to'] ?? '21:00');
    }

    /** وصل وارد في الساعة نفسها من اليوم خلال آخر 14 يوماً: الصمت الآن غير معتاد */
    public static function usuallyBusy(?Carbon $at = null): bool
    {
        // بتوقيت التطبيق كما تُخزَّن created_at، وإلا قورنت ساعة عمّان بساعة UTC
        $now = ($at ?? now())->copy()->setTimezone(config('app.timezone'));
        for ($d = 1; $d <= self::LOOKBACK_DAYS; $d++) {
            $from = $now->copy()->subDays($d)->startOfHour();
            if (WaMessage::where('direction', 'in')->where('created_at', '>=', $from)->where('created_at', '<', $from->copy()->addHour())->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * فحص واحد (whatsapp:inbound-silence كل 15 دقيقة): disabled | outside_hours | recent | quiet_period | already_alerted | alerted.
     */
    public static function check(?Carbon $at = null): string
    {
        if (!FeatureFlags::on('inbound_silence_alert')) {
            return 'disabled';
        }
        $at ??= now();
        if (!self::inBusinessHours($at)) {
            return 'outside_hours';
        }
        $minutes = self::minutesSinceLast($at);
        if ($minutes !== null && $minutes < (int) config('ops.inbound_silence_minutes', 180)) {
            return 'recent';
        }
        if (!self::usuallyBusy($at)) {
            return 'quiet_period';
        }
        if (!OpsHealth::store()->add(self::ALERTED_KEY, 1, self::REALERT_HOURS * 3600)) {
            return 'already_alerted';
        }

        $since = $minutes === null ? 'مدة طويلة' : intdiv($minutes, 60).' ساعة';
        app(OpsAlerts::class)->notifyAdmins('inbound_silence', '📭 لا وارد من واتساب',
            "لم يصل أي حدث من ميتا منذ {$since} في وقت الدوام. تحقّق من رابط الويبهوك في App Dashboard، ثم من صفحة حالة المنصة.",
            OpsAlerts::URL);

        return 'alerted';
    }
}
