<?php

namespace App\Services\WhatsApp\Sending;

use App\Models\WaBotSetting;
use App\Models\WaChannel;
use App\Models\WaNotificationPref;
use App\Models\WaOptout;
use App\Models\WaOutbox;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

/**
 * محرك حدود الإرسال الاستباقي (2026-11-06): يحسم كل صف من wa_outbox قبل إرساله. الحدود من wa_send_limits لكل رقم ونوع
 * (LimitResolver)، والعدّ من الطابور نفسه (UsageCounter). الترتيب (docs/whatsapp/limits.md):
 *
 *   الإيقاف العام ← الرقم موقوف ← الرقم غير متصل ← قاطع الإخفاقات ← opt-out/حظر/كتم (رفض نهائي) ← الصلاحية
 *   ← نافذة الساعات والأيام ← سقوف النوع (دقيقة/ساعة/يوم) ← سقف الرقم اليومي (والتسخين) ← المحادثات الباردة
 *   ← سقف العميل اليومي ← الفاصل بين رسالتين على الرقم
 *
 * صفوف الاختبار (is_test، رقم اختبار) لا تُعدّ ولا تخضع للسقوف ولا للنافذة: إيقاف ورقم واتصال وحدها.
 * الردود (بوت وموظف) لا تمر من هنا أبداً: أقفال WhatsAppChannel::gate وحدها (المسار السريع).
 */
final class SendGovernor
{
    public static function decide(WaOutbox $item, WaChannel $ch, ?CarbonInterface $now = null): Decision
    {
        $now ??= now();
        if (WaBotSetting::current()->sendingSuspended()) {
            return Decision::defer($now->copy()->addMinutes(10), 'الإيقاف العام للإرسال', 'suspended');
        }
        if ($ch->isPaused()) {
            return Decision::defer($ch->paused_until->copy(), 'الرقم موقوف مؤقتاً', 'channel_paused');
        }
        if (!$ch->isReady()) {
            return Decision::defer($now->copy()->addMinutes(5), 'الرقم غير متصل', 'channel_down');
        }
        if (FailureBreaker::open($ch)) {
            return Decision::defer(FailureBreaker::until($ch) ?? $now->copy()->addMinutes(15), 'قاطع الإخفاقات', 'breaker');
        }
        if ($item->expires_at !== null && $item->expires_at->lt($now)) {
            return Decision::expire();
        }
        if ($item->is_test) {
            return Decision::allow();
        }

        $phone = (string) $item->phone;
        if ($phone !== '' && WaOptout::whereIn('phone', array_unique([$phone, (string) \App\Services\WhatsApp\ContactIdentity::phoneFrom($phone)]))->exists()) {
            return Decision::deny('أوقف العميل الرسائل', 'optout');
        }
        if ($item->conversation?->isBlocked()) {
            return Decision::deny('العميل محظور', 'blocked');
        }
        if ($item->client_id && self::muted((int) $item->client_id, (string) $item->type_key, $now)) {
            return Decision::deny('كتم العميل هذا النوع من الرسائل', 'muted');
        }

        $type = $item->type_key ?: null;
        $L = LimitResolver::for((int) $ch->id, $type);
        $tz = UsageCounter::tz();
        $local = $now->copy()->setTimezone($tz);
        [$from, $to] = [max(0, min(23, (int) $L['window_from'])), max(1, min(24, (int) $L['window_to']))];
        if (!in_array((int) $local->isoWeekday(), $L['weekdays'], true) || $local->hour < $from || $local->hour >= $to) {
            return Decision::defer(self::nextWindow($local, $from, $to, $L['weekdays'])->setTimezone(config('app.timezone')), 'خارج نافذة الإرسال', 'window');
        }
        $windowEnd = $local->copy()->setTime($to === 24 ? 23 : $to, $to === 24 ? 59 : 0);

        // سقوف النوع على الرقم
        if ($type !== null) {
            foreach ([['per_minute', $now->copy()->startOfMinute(), $now->copy()->startOfMinute()->addMinute(), 'الدقيقة'],
                ['per_hour', $now->copy()->startOfHour(), $now->copy()->startOfHour()->addHour(), 'الساعة'],
                ['per_day', UsageCounter::dayStart($now), null, 'اليوم']] as [$field, $since, $end, $label]) {
                $cap = $L[$field];
                if ($cap !== null && UsageCounter::sent((int) $ch->id, $type, $since) >= (int) $cap) {
                    $end ??= self::nextWindow($local->copy()->addDay()->startOfDay(), $from, $to, $L['weekdays'])->setTimezone(config('app.timezone'));

                    return Decision::defer($end, "سقف {$label} لهذا النوع", 'cap_'.$field);
                }
            }
        }

        // سقف الرقم اليومي لكل الأنواع، ولا يتجاوز سلّم التسخين
        $all = LimitResolver::for((int) $ch->id, null);
        $usedDay = UsageCounter::sent((int) $ch->id, null, UsageCounter::dayStart($now));
        $dayCap = min(WarmupLadder::capFor($ch), $all['per_day'] !== null ? (int) $all['per_day'] : PHP_INT_MAX);
        $tomorrow = fn () => self::nextWindow($local->copy()->addDay()->startOfDay(), $from, $to, $L['weekdays'])->setTimezone(config('app.timezone'));
        if ($usedDay >= $dayCap) {
            return Decision::defer($tomorrow(), 'سقف الرقم اليومي (التسخين)', 'cap_channel_day');
        }
        foreach ([['per_minute', $now->copy()->startOfMinute(), $now->copy()->startOfMinute()->addMinute()], ['per_hour', $now->copy()->startOfHour(), $now->copy()->startOfHour()->addHour()]] as [$field, $since, $end]) {
            if ($all[$field] !== null && UsageCounter::sent((int) $ch->id, null, $since) >= (int) $all[$field]) {
                return Decision::defer($end, 'سقف الرقم في '.($field === 'per_minute' ? 'الدقيقة' : 'الساعة'), 'cap_channel_'.$field);
            }
        }

        if (self::isCold($item, $ch) && $L['cold_per_day'] !== null && UsageCounter::cold((int) $ch->id, UsageCounter::dayStart($now)) >= (int) $L['cold_per_day']) {
            return Decision::defer($tomorrow(), 'سقف المحادثات الجديدة اليومي', 'cap_cold');
        }
        if ($L['per_recipient_day'] !== null && UsageCounter::recipient($phone, UsageCounter::dayStart($now)) >= (int) $L['per_recipient_day']) {
            return Decision::defer($tomorrow(), 'سقف العميل اليومي', 'cap_recipient');
        }

        $last = UsageCounter::lastSentAt((int) $ch->id);
        if ($last !== null) {
            $rand = (crc32($ch->id.'|'.$last->getTimestamp()) % 10000) / 10000;
            $gap = Spreader::gapSeconds($L, $now, $windowEnd->setTimezone(config('app.timezone')), $usedDay, $dayCap === PHP_INT_MAX ? null : $dayCap, $rand);
            if ($last->copy()->addSeconds($gap)->gt($now)) {
                return Decision::defer($last->copy()->addSeconds($gap), 'الفاصل بين الرسائل', 'gap');
            }
        }

        return Decision::allow();
    }

    /** محادثة بلا أي رسالة سابقة على هذا الرقم = باردة (أول تواصل منا) */
    public static function isCold(WaOutbox $item, WaChannel $ch): bool
    {
        if (!$item->conversation_id) {
            return true;
        }

        return !\App\Models\WaMessage::where('conversation_id', $item->conversation_id)->where('channel_id', $ch->id)->exists();
    }

    private static function muted(int $clientId, string $typeKey, CarbonInterface $now): bool
    {
        if (!Schema::hasTable('wa_notification_prefs')) {
            return false;
        }
        $group = \App\Services\WhatsApp\System\Catalog::groupOf($typeKey);

        return WaNotificationPref::query()->where('client_id', $clientId)
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('group')->whereNull('type_key'))
                ->orWhere('type_key', $typeKey)->orWhere(fn ($w) => $w->whereNull('type_key')->where('group', $group)))
            ->where(fn ($q) => $q->whereNull('muted_until')->orWhere('muted_until', '>', $now))
            ->exists();
    }

    /** أول لحظة داخل النافذة بعد $local (بتوقيت عمّان) */
    private static function nextWindow(CarbonInterface $local, int $from, int $to, array $weekdays): CarbonInterface
    {
        $t = $local->copy();
        for ($i = 0; $i < 8; $i++) {
            $start = $t->copy()->setTime($from, 0);
            if (in_array((int) $t->isoWeekday(), $weekdays, true)) {
                if ($t->lt($start)) {
                    return $start;
                }
                if ($t->hour < $to) {
                    return $t;
                }
            }
            $t = $t->copy()->addDay()->startOfDay();
        }

        return $local->copy()->addDay()->setTime($from, 0);
    }
}
