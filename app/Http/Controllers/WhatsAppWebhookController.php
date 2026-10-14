<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * ويبهوك ميتا — خارج auth وخارج CSRF.
 * قاعدة حاكمة: تحقّق التوقيع، ثمّ خزّن، ثمّ ردّ 200 (وإلا تعيد ميتا الإرسال وقد تعطّل الويبهوك).
 * الاستثناء الوحيد: عطل القاعدة مع العلم webhook_5xx يرد 503 لتعيد ميتا ما لم يُخزَّن.
 */
class WhatsAppWebhookController extends Controller
{
    /** تحقّق ميتا عند ربط الويبهوك */
    public function verify(Request $request)
    {
        $s = WaBotSetting::current();
        $expected = (string) $s->verifyToken();

        // مقارنة بصمتين بطول ثابت: hash_equals على النصين يكشف الطول عند الاختلاف
        $key = (string) config('app.key');
        $given = (string) $request->query('hub_verify_token', '');
        $matches = $expected !== '' && hash_equals(hash_hmac('sha256', $expected, $key), hash_hmac('sha256', $given, $key));

        // التحدي يُعاد نصاً كما هو: نقبل الشكل الذي ترسله ميتا فقط
        $challenge = (string) $request->query('hub_challenge', '');

        if ($request->query('hub_mode') === 'subscribe' && $matches && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $challenge)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        WaLog::warning('webhook.verify.denied');

        return response('Forbidden', 403);
    }

    /** استقبال الرسائل والحالات */
    public function receive(Request $request)
    {
        $s = WaBotSetting::current();
        $raw = $request->getContent();

        // التوقيع إلزامي — لا استثناء في الإنتاج
        if (!WhatsAppChannel::verifySignature($raw, $request->header('X-Hub-Signature-256'), $s->appSecret())) {
            WaLog::warning('webhook.bad_signature');
            return response()->json(['ok' => false], 403);
        }

        $touched = [];
        $status = 200;
        try {
            $payload = json_decode($raw, true) ?: [];
            WhatsAppInbox::handleWebhook($payload, $touched);
        } catch (QueryException|\PDOException $e) {
            // عطل قاعدة (مقفلة، قرص ممتلئ): 503 تجعل ميتا تعيد الإرسال، وwamid الفريد يمنع الازدواج.
            // خلف العلم webhook_5xx (يُفعَّل بعد WAL)، وإلا 200 كما كان
            WaLog::error('webhook.db_error', ['exception' => $e]);
            if ($s->flag('webhook_5xx')) {
                $status = 503;
            }
        } catch (\Throwable $e) {
            // حمولة معطوبة أو خلل منطق: 200 دائماً، فالإعادة لن تصلحه وميتا تعيد 7 أيام
            WaLog::error('webhook.error', ['exception' => $e]);
        }

        // ما خُزّن قبل أي استثناء يُمرَّر للبوت أيضاً. كل جدولة مستقلة، وفشلها لا يغيّر الرد (يلتقطه المُصالِح)
        foreach (array_unique($touched) as $id) {
            try {
                ProcessWhatsAppReply::schedule($id);
            } catch (\Throwable $e) {
                WaLog::error('webhook.schedule_failed', ['conv_id' => $id, 'exception' => $e]);
            }
        }

        return response()->json(['ok' => $status === 200], $status);
    }
}
