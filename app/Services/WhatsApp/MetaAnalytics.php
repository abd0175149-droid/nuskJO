<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Models\WaMessage;
use App\Services\Ops\OpsHealth;
use App\Services\WhatsApp\Graph\GraphClient;
use Illuminate\Support\Carbon;

/**
 * المطابقة الاختيارية مع تحليلات ميتا للحساب (E24، العلم meta_analytics_reconcile، مطفأ): نداء GET واحد يومياً على
 * /{WABA_ID}?fields=analytics.start().end().granularity(DAY) عبر GraphClient، لأمس بتوقيت عمّان، ويُخزَّن يوماً في
 * مخزن ops. يُعرض «ميتا تقول: أُرسل X وسُلّم Y» بجانب عدّنا للمقارنة فقط: أرقام ميتا تقريبية ولا تغيّر شيئاً عندنا.
 * يحتاج معرّف الحساب (WA_WABA_ID أو اليدوي أو المتعلَّم) والصلاحية whatsapp_business_management (موجودة).
 */
final class MetaAnalytics
{
    public const KEY = 'wa:meta:analytics';

    public static function last(): ?array
    {
        try {
            $v = OpsHealth::store()->get(self::KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_array($v) ? $v : null;
    }

    /** @return array{ok: bool, reason?: string, day?: string, meta_sent?: int, meta_delivered?: int, ours?: int} */
    public static function refresh(): array
    {
        $s = WaBotSetting::current();
        $waba = WebhookFence::account($s)['id'] ?? ($s->wa_waba_id ? (string) $s->wa_waba_id : null);
        if (!$s->channelReady() || $waba === null || !preg_match('/^\d{5,32}$/', $waba)) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }
        $day = Carbon::now('Asia/Amman')->subDay()->startOfDay();
        $start = $day->getTimestamp();
        $end = $day->copy()->addDay()->getTimestamp();

        try {
            $res = GraphClient::request('GET', [$waba], ['fields' => "analytics.start({$start}).end({$end}).granularity(DAY)"], (string) $s->token(), 20, 5);
        } catch (\Throwable $e) {
            WaLog::warning('wa.meta_analytics.failed', ['exception' => $e]);

            return ['ok' => false, 'reason' => 'network'];
        }
        if (!$res->successful()) {
            $err = MetaError::fromGraphResponse($res);
            WaLog::warning('wa.meta_analytics.failed', ['meta_code' => $err->metaCode, 'error_code' => $err->code, 'http' => $res->status()]);

            return ['ok' => false, 'reason' => 'meta_error'];
        }

        $sent = $delivered = 0;
        foreach ((array) data_get($res->json(), 'analytics.data_points', []) as $p) {
            $sent += (int) ($p['sent'] ?? 0);
            $delivered += (int) ($p['delivered'] ?? 0);
        }
        $ours = WaMessage::where('direction', 'out')->where('source', '!=', OutboundCounter::TEST_SOURCE)->excludeUndelivered()
            ->whereBetween('created_at', [$day->copy()->utc(), $day->copy()->addDay()->utc()])->count();
        $result = ['ok' => true, 'day' => $day->toDateString(), 'meta_sent' => $sent, 'meta_delivered' => $delivered, 'ours' => $ours,
            'at' => now()->toIso8601String()];
        OpsHealth::store()->put(self::KEY, $result, now()->addHours(26));

        return $result;
    }
}
