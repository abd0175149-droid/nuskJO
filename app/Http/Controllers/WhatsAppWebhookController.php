<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Http\Request;

/**
 * ويبهوك ميتا — خارج auth وخارج CSRF.
 * قاعدة حاكمة: تحقّق التوقيع، ثمّ ردّ 200 فوراً، ثمّ عالِج (وإلا تعيد ميتا الإرسال وقد تعطّل الويبهوك).
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

        \App\Services\WhatsApp\WaLog::warning('webhook.verify.denied');

        return response('Forbidden', 403);
    }

    /** استقبال الرسائل والحالات */
    public function receive(Request $request)
    {
        $s = WaBotSetting::current();
        $raw = $request->getContent();

        // التوقيع إلزامي — لا استثناء في الإنتاج
        if (!WhatsAppChannel::verifySignature($raw, $request->header('X-Hub-Signature-256'), $s->appSecret())) {
            \App\Services\WhatsApp\WaLog::warning('webhook.bad_signature');
            return response()->json(['ok' => false], 403);
        }

        try {
            $payload = json_decode($raw, true) ?: [];
            $convIds = WhatsAppInbox::handleWebhook($payload);

            // التمرير للبوت عبر الطابور — لا معالجة ثقيلة داخل الويبهوك
            foreach ($convIds as $id) {
                ProcessWhatsAppReply::schedule($id);
            }
        } catch (\Throwable $e) {
            // لا نُفشل الويبهوك أبداً — نسجّل ونردّ 200
            \App\Services\WhatsApp\WaLog::error('webhook.error', ['exception' => $e]);
        }

        return response()->json(['ok' => true], 200);
    }
}
