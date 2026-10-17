<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WebhookIntake;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ويبهوك ميتا — خارج auth وخارج CSRF وخارج مجموعة web (routes/webhooks.php).
 * قاعدة حاكمة: تحقّق التوقيع، ثمّ احفظ الجسم مشفّراً، ثمّ عالج، ثمّ ردّ 200 (وإلا تعيد ميتا الإرسال 7 أيام).
 * الاستثناء الوحيد: عطل القاعدة قبل أي حفظ مع العلم webhook_5xx يرد 503 لتعيد ميتا ما لم يُخزَّن.
 * العقد كاملاً: docs/whatsapp-webhook-contract.md
 */
class WhatsAppWebhookController extends Controller
{
    /** تحقّق ميتا عند ربط الويبهوك */
    public function verify(Request $request)
    {
        $s = WaBotSetting::current();
        // القاعدة تحفظ هاش الرمز وحده (E10)؛ مقارنة هاشين بطول ثابت لا تكشف طول الرمز
        $expected = (string) $s->verifyTokenHash();
        $given = (string) $request->query('hub_verify_token', '');
        $matches = $expected !== '' && hash_equals($expected, hash('sha256', $given));

        // التحدي يُعاد نصاً كما هو: نقبل الشكل الذي ترسله ميتا فقط
        $challenge = (string) $request->query('hub_challenge', '');

        if ($request->query('hub_mode') === 'subscribe' && $matches && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $challenge)) {
            // «آخر مصافحة ناجحة» في الإعدادات، بلا كتابة في صف الإعدادات
            Cache::forever('wa:verify:last_handshake_at', now()->toIso8601String());

            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        WaLog::warning('webhook.verify.denied');
        // عيّنة لكل عنوان، بلا الرمز المرسل
        AuditLog::sampled(AuditAction::WaWebhookVerifyFailed, 'wa_verify:'.$request->ip(), 'webhook', null, null, [
            'mode' => mb_substr((string) $request->query('hub_mode', ''), 0, 20), 'verify_param_sent' => $given !== '',
        ]);

        return response('Forbidden', 403);
    }

    /**
     * استقبال الرسائل والحالات (E20). الترتيب: الحجم، ثم التوقيع، ثم الحفظ المشفّر، ثم المعالجة المعزولة.
     * بعد الحفظ الرد 200 دائماً (الفاشل يُعاد بـ whatsapp:replay-webhooks). 503 لفشل الحفظ العابر وحده.
     */
    public function receive(Request $request)
    {
        @set_time_limit((int) config('whatsapp.webhook.time_limit', 55));
        $raw = $request->getContent();

        // قبل حساب HMAC: حد مساوٍ لكتلة nginx (ميتا لا ترسل أكثر من 3MB)
        if (strlen($raw) > (int) config('whatsapp.webhook.max_bytes')) {
            WaLog::warning('webhook.too_large', ['bytes' => strlen($raw)]);

            return response()->json(['ok' => false], 413);
        }

        $s = WaBotSetting::current();
        // التوقيع إلزامي — لا استثناء في الإنتاج
        // appSecret() لا يرمي إن تعذّر فك السر: null فرفض 403، وميتا تعيد 7 أيام
        if (!WhatsAppChannel::verifySignature($raw, $request->header('X-Hub-Signature-256'), $s->appSecret())) {
            // الفاشل وحده يُعدّ، ولا حد ولا 429 أبداً (الطلب الموقَّع لا يمر من هنا)
            $count = WebhookIntake::badSignature();
            WaLog::warning('webhook.bad_signature', ['count' => $count]);
            // عيّنة لكل عنوان: الجسم لا يُسجَّل
            AuditLog::sampled(AuditAction::WaWebhookBadSignature, 'wa_sig:'.$request->ip(), 'webhook', null, null, [
                'has_header' => $request->hasHeader('X-Hub-Signature-256'), 'bytes' => strlen($raw),
            ]);

            return response()->json(['ok' => false], 403);
        }

        // «اختبار الاتصال» (E23، العلم channel_selftest): ارتداد موقّع منا يُعترض هنا، قبل «آخر توقيع صحيح» والحفظ والسياج
        // والمعالجة، فلا يترك أثراً في المحادثات ولا في سجل الويبهوك ولا يوقظ البوت
        if (\App\Services\WhatsApp\ChannelSelfTest::intercepts($raw)) {
            return response()->json(['ok' => true]);
        }

        // «آخر توقيع صحيح» في الإعدادات: كتابة واحدة في الدقيقة على الأكثر
        if (Cache::add('wa:sig:ok:tick', 1, 60)) {
            Cache::forever('wa:sig:last_ok_at', now()->toIso8601String());
        }

        $event = null;
        if (WebhookIntake::storesAll()) {
            try {
                [$event, $settled] = WebhookIntake::store($raw);
                if ($settled) {
                    // إعادة من ميتا لحمولة حُسمت: wamid الفريد كان سيحميها أيضاً
                    return response()->json(['ok' => true]);
                }
            } catch (QueryException|\PDOException $e) {
                // عطل قاعدة عابر قبل أي معالجة: 503 تجعل ميتا تعيد الإرسال (خلف webhook_5xx)، وإلا معالجة بلا سجل كما كان
                WaLog::error('webhook.store_failed', ['exception' => $e]);
                if ($s->flag('webhook_5xx')) {
                    return response()->json(['ok' => false], 503)->header('Retry-After', '30');
                }
            }
        }

        try {
            $result = WebhookIntake::process($event, $raw);
        } catch (\Throwable $e) {
            // خارج العناصر المعزولة (السياج أو قراءة الإعدادات): محاولة فاشلة، والجسم المحفوظ يُعاد بأمر الإعادة
            WaLog::error('webhook.error', ['exception' => $e]);
            $result = null;
            if ($event !== null) {
                rescue(fn () => WebhookIntake::failWhole($event, $e), report: false);
            }
            if ($event === null && ($e instanceof QueryException || $e instanceof \PDOException) && $s->flag('webhook_5xx')) {
                return response()->json(['ok' => false], 503);
            }
        }

        if ($event === null && $result?->failed()) {
            // بلا سجل محفوظ قبل المعالجة: وضع failures يحفظ الجسم الآن للإعادة، وإلا خطأ القاعدة العابر يرد 503 (E07)
            $stored = false;
            if (WebhookIntake::storesFailures()) {
                try {
                    WebhookIntake::storeFailed($raw, $result);
                    $stored = true;
                } catch (QueryException|\PDOException $e) {
                    WaLog::error('webhook.store_failed', ['exception' => $e]);
                }
            }
            if (!$stored && $result->transient && $s->flag('webhook_5xx')) {
                return response()->json(['ok' => false], 503);
            }
        }

        return response()->json(['ok' => true]);
    }
}
