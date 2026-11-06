<?php

namespace App\Services\WhatsApp\System;

use App\Models\Invoice;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaOutbox;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\Sending\Outbox;
use App\Services\WhatsApp\WaLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * يحوّل حدثاً أو تاريخاً إلى صفوف في wa_outbox (المفتاح send_key = نوع:مصدر:تكرار، فلا تكرار عند إعادة المسح):
 *
 * - النوع مطفأ أو وضعه off: لا جدولة، والمفتوح منه للمصدر يُلغى.
 * - المرحلة test أو وضع الاختبار العام (system_test_mode): الصف يذهب لكل رقم من أرقام الاختبار (test_recipients) بدل
 *   العميل، بمفتاح …:test:{n} وis_test، فلا يصل العميل شيء قبل «إطلاق» النوع (قرار المالك الثالث).
 * - الوضع approve: الصف awaiting_approval حتى يعتمده موظف من صفحة الطابور.
 * النص لا يُخزَّن هنا: يُصيَّر لحظة الإرسال (System\Renderer) من القالب والبيانات الحالية.
 */
final class Scheduler
{
    /**
     * @param  array{date?: string}  $anchor  التاريخ الذي بُني عليه الموعد، فيُكشف تغيّره لحظة الإرسال
     */
    public static function schedule(string $typeKey, string $sourceType, int $sourceId, CarbonInterface $due, string $occurrence = '1', array $anchor = [], array $extra = []): ?WaOutbox
    {
        $type = Catalog::effective($typeKey);
        $base = "{$typeKey}:{$sourceType}:{$sourceId}:{$occurrence}";
        if ($type === null || !$type['enabled'] || $type['mode'] === 'off') {
            Outbox::cancel($base, 'النوع مطفأ');

            return null;
        }
        $ctx = Context::for($typeKey, $sourceType, $sourceId, $anchor);
        if (!$ctx['valid']) {
            Outbox::cancel($base, (string) $ctx['reason']);

            return null;
        }
        $phone = $extra['phone'] ?? $ctx['phone'] ?? null;
        $digits = PhoneNormalizer::canonical((string) $phone);
        if ($digits === '' && !self::testing($type)) {
            WaLog::info('wa.system.no_phone', ['type_key' => $typeKey, 'event' => $sourceType]);

            return null;
        }
        $conv = self::conversationFor($digits, $ctx['conversation_id'] ?? null, $type['channel_id']);
        $row = [
            'channel_id' => $type['channel_id'] ?: $conv?->channel_id,
            'conversation_id' => $conv?->id,
            'client_id' => $ctx['client_id'] ?? null,
            'phone' => $digits ?: null,
            'type_key' => $typeKey,
            'lane' => $typeKey === 'lead.followup' ? 'followup' : 'system',
            'payload' => ['kind' => 'system', 'anchor' => $anchor] + (isset($extra['text']) ? ['text' => mb_substr((string) $extra['text'], 0, 4096)] : []),
            'priority' => (int) $type['priority'],
            'due_at' => $due,
            'expires_at' => $due->copy()->addMinutes(max(30, (int) $type['expires_minutes'])),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'status' => $type['mode'] === 'approve' ? WaOutbox::AWAITING : WaOutbox::PENDING,
            'created_by' => $extra['created_by'] ?? null,
        ];

        if (self::testing($type)) {
            Outbox::cancel($base, 'النوع في مرحلة الاختبار');
            $first = null;
            foreach (self::testRecipients() as $i => $test) {
                $first ??= Outbox::put("{$base}:test:{$i}", ['channel_id' => $type['channel_id'], 'conversation_id' => null, 'phone' => $test,
                    'is_test' => true, 'status' => WaOutbox::PENDING, 'payload' => $row['payload'] + ['test_of' => $digits !== '' ? substr($digits, -4) : null]] + $row);
            }

            return $first;
        }

        return Outbox::put($base, $row);
    }

    /** يجدول تذكيرات الفاتورة كلها من بياناتها الآن (الرحلة والاستحقاق والعودة)، ويلغي ما لم يعد ينطبق */
    public static function syncInvoice(Invoice $invoice): void
    {
        if ($invoice->status !== 'approved') {
            Outbox::cancelFor('invoice', (int) $invoice->id, 'الفاتورة لم تعد معتمدة');

            return;
        }
        $now = now();
        $plan = [];
        if ($invoice->trip_date) {
            $trip = Carbon::parse($invoice->trip_date)->toDateString();
            foreach (['trip.reminder', 'trip.eve', 'trip.arrival'] as $key) {
                $plan[$key] = [$trip, self::occurrences($key, $trip)];
            }
        }
        if ($invoice->due_date) {
            $due = Carbon::parse($invoice->due_date)->toDateString();
            $plan['payment.due'] = [$due, self::occurrences('payment.due', $due)];
        }
        if ($invoice->offer_id && ($ret = $invoice->offer?->return_date)) {
            $plan['trip.return'] = [$ret->toDateString(), self::occurrences('trip.return', $ret->toDateString())];
        }
        foreach ($plan as $key => [$date, $occ]) {
            $keep = [];
            foreach ($occ as $n => $at) {
                if ($at->lt($now->copy()->subHour())) {
                    continue;
                }
                $item = self::schedule($key, 'invoice', (int) $invoice->id, $at, "{$date}:{$n}", ['date' => $date]);
                if ($item) {
                    $keep[] = $item->id;
                }
            }
            // مواعيد قديمة لتاريخ تغيّر: تُلغى
            WaOutbox::where('source_type', 'invoice')->where('source_id', $invoice->id)->where('type_key', $key)
                ->whereIn('status', WaOutbox::OPEN)->whereNotIn('id', $keep)->where('is_test', false)
                ->update(['status' => WaOutbox::CANCELLED, 'defer_reason' => 'تغيّر الموعد', 'updated_at' => now()]);
        }
    }

    /**
     * مواعيد نوع لتاريخ مرساة: كل offset من التوقيت بالساعة at بتوقيت عمّان.
     *
     * @return list<CarbonInterface>
     */
    public static function occurrences(string $typeKey, string $date): array
    {
        $t = (array) (Catalog::effective($typeKey)['timing'] ?? []);
        $offsets = (array) ($t['offsets'] ?? [($t['offset_days'] ?? 0)]);
        [$h, $m] = array_map('intval', explode(':', (string) ($t['at'] ?? '10:00')) + [0, 0]);
        $out = [];
        foreach ($offsets as $d) {
            $out[] = Carbon::parse($date, 'Asia/Amman')->addDays((int) $d)->setTime($h, $m)->setTimezone(config('app.timezone'));
        }

        return $out;
    }

    /** الموعد الفوري: الآن + delay_minutes */
    public static function soon(string $typeKey): CarbonInterface
    {
        return now()->addMinutes((int) (Catalog::effective($typeKey)['timing']['delay_minutes'] ?? 0));
    }

    /** ملخص اليوم لكل رقم إدارة فعّال */
    public static function adminSummary(CarbonInterface $day): int
    {
        $n = 0;
        $date = $day->copy()->setTimezone('Asia/Amman')->toDateString();
        $at = self::occurrences('admin.summary', $date)[0];
        if ($at->lt(now()->subMinutes(30))) {
            return 0;
        }
        foreach (WaAdminNumber::query()->where('is_active', true)->get(['id', 'phone']) as $a) {
            if (self::schedule('admin.summary', 'admin', (int) $a->id, $at, $date, ['date' => $date], ['phone' => $a->phone])) {
                $n++;
            }
        }

        return $n;
    }

    public static function testing(array $type): bool
    {
        return $type['stage'] !== 'live' || self::globalTestMode();
    }

    public static function globalTestMode(): bool
    {
        return Schema::hasColumn('wa_bot_settings', 'system_test_mode') && (bool) WaBotSetting::current()->system_test_mode;
    }

    /** @return list<string> أرقام الاختبار بأرقام دولية */
    public static function testRecipients(): array
    {
        if (!Schema::hasColumn('wa_bot_settings', 'test_recipients')) {
            return [];
        }
        $raw = WaBotSetting::current()->test_recipients;
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;

        return array_values(array_unique(array_filter(array_map(fn ($p) => PhoneNormalizer::canonical((string) $p), (array) $raw))));
    }

    /** المحادثة القائمة للعميل: على الرقم المحدد للنوع، وإلا أحدث محادثة له على أي رقم فعّال */
    private static function conversationFor(string $digits, ?int $conversationId, ?int $channelId): ?WaConversation
    {
        if ($conversationId) {
            $conv = WaConversation::find($conversationId);
            if ($conv && $conv->channel_id && (!$channelId || (int) $conv->channel_id === $channelId)) {
                return $conv;
            }
        }
        if ($digits === '') {
            return null;
        }
        $canonical = \App\Services\WhatsApp\ContactIdentity::phoneFrom($digits);

        return WaConversation::query()->where('phone', $canonical)->whereNotNull('channel_id')->where('status', '!=', 'merged')
            ->when($channelId, fn ($q) => $q->where('channel_id', $channelId))
            ->whereIn('channel_id', \App\Models\WaChannel::query()->active()->select('id'))
            ->orderByDesc('last_message_at')->first();
    }
}
