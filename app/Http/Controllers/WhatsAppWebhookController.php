<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppReply;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        $expected = $s->verifyToken();

        if ($request->query('hub_mode') === 'subscribe'
            && $expected
            && hash_equals((string) $expected, (string) $request->query('hub_verify_token'))) {
            return response($request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /** استقبال الرسائل والحالات */
    public function receive(Request $request)
    {
        $s = WaBotSetting::current();
        $raw = $request->getContent();

        // التوقيع إلزامي — لا استثناء في الإنتاج
        if (!WhatsAppChannel::verifySignature($raw, $request->header('X-Hub-Signature-256'), $s->appSecret())) {
            Log::warning('WA webhook: bad signature');
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
            Log::error('WA webhook error: ' . $e->getMessage());
        }

        return response()->json(['ok' => true], 200);
    }
}
