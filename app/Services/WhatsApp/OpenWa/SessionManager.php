<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Models\WaChannel;
use App\Services\WhatsApp\WaLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * دورة حياة جلسة الرقم على البوابة. الإنشاء والمفاتيح والـ webhooks بالمفتاح الرئيس (admin)، والتشغيل والربط بمفتاح الرقم.
 *
 * provision(): جلسة ← مفتاح operator محصور بها (allowedSessions) ← webhook بسر جديد بالأحداث المطلوبة. كل خطوة تُحفظ
 * فور نجاحها، فإعادة النداء بعد فشل جزئي تكمل من حيث توقفت ولا تنشئ جلسة ثانية. repair() يعيد المفتاح والـ webhook
 * لجلسة قائمة (بعد فقدان main.sqlite في حجم البوابة، أو تغيّر قائمة الأحداث).
 *
 * @return array{ok: bool, code?: string, message?: string} في كل الدوال العامة
 */
final class SessionManager
{
    public static function provision(WaChannel $channel): array
    {
        $admin = OpenWaClient::admin();
        if (!$admin->configured()) {
            return self::fail('GW_AUTH', 'مفتاح البوابة الرئيس غير مضبوط (OPENWA_ADMIN_KEY).');
        }

        if (!$channel->openwa_session_id) {
            $res = $admin->request('POST', '/sessions', ['name' => self::sessionName($channel)]);
            $id = (string) $res->json('id', '');
            if (!$res->ok || $id === '') {
                return self::failed('create', $res);
            }
            $channel->forceFill(['openwa_session_id' => $id, 'status' => (string) $res->json('status', 'created'), 'status_at' => now()])->save();
            $channel->logEvent('created');
        }

        if (!$channel->api_key) {
            $key = self::issueKey($channel);
            if (!$key['ok']) {
                return $key;
            }
        }

        if (!$channel->webhook_id) {
            $hook = self::syncWebhook($channel);
            if (!$hook['ok']) {
                return $hook;
            }
        }

        return ['ok' => true];
    }

    /** مفتاح جديد وwebhook جديد لجلسة قائمة: يُلغى القديمان إن وُجدا */
    public static function repair(WaChannel $channel): array
    {
        if (!$channel->openwa_session_id) {
            return self::provision($channel);
        }
        $channel->forceFill(['api_key' => null])->save();
        $key = self::issueKey($channel);
        if (!$key['ok']) {
            return $key;
        }

        return self::syncWebhook($channel, replace: true);
    }

    /** يسجّل webhook الرقم بالعنوان والأحداث الحالية وسر جديد، ويحذف السابق إن طُلب */
    public static function syncWebhook(WaChannel $channel, bool $replace = false): array
    {
        $admin = OpenWaClient::admin();
        $sid = rawurlencode((string) $channel->openwa_session_id);
        if ($replace && $channel->webhook_id) {
            $admin->request('DELETE', "/sessions/{$sid}/webhooks/".rawurlencode((string) $channel->webhook_id));
        }
        $secret = Str::random(48);
        $res = $admin->request('POST', "/sessions/{$sid}/webhooks", [
            'url' => $channel->webhookUrl(),
            'events' => array_values((array) config('whatsapp.openwa.events', ['*'])),
            'secret' => $secret,
            'retryCount' => (int) config('whatsapp.openwa.webhook_retry_count', 3),
        ]);
        $id = (string) $res->json('id', '');
        if (!$res->ok || $id === '') {
            return self::failed('webhook', $res);
        }
        $channel->forceFill(['webhook_id' => $id, 'webhook_secret' => $secret])->save();
        $channel->logEvent('webhook', ['replaced' => $replace]);

        return ['ok' => true];
    }

    public static function start(WaChannel $channel): array
    {
        $res = OpenWaClient::for($channel)->session('POST', '/start', null, 'start');
        if (!$res->ok && $res->status() !== 409) {
            return self::failed('start', $res);
        }
        self::refresh($channel);

        return ['ok' => true];
    }

    public static function stop(WaChannel $channel): array
    {
        $res = OpenWaClient::for($channel)->session('POST', '/stop', null, 'start');

        return $res->ok ? ['ok' => true] : self::failed('stop', $res);
    }

    /** stop ثم 5 ثوانٍ ثم start (درس WB web: إعادة التشغيل تصلح الجلسة العالقة على «ready») */
    public static function restart(WaChannel $channel, string $reason = 'manual'): array
    {
        self::stop($channel);
        \Illuminate\Support\Sleep::for(5)->seconds();
        $res = self::start($channel);
        $channel->forceFill(['last_restart_at' => now()])->save();
        $channel->logEvent('restart', ['reason' => mb_substr($reason, 0, 40), 'ok' => $res['ok']]);

        return $res;
    }

    /** فك الربط من الهاتف: الجلسة تبقى، ويلزم QR جديد */
    public static function logout(WaChannel $channel): array
    {
        $res = OpenWaClient::for($channel)->session('POST', '/logout', null, 'start');
        if (!$res->ok) {
            return self::failed('logout', $res);
        }
        $channel->forceFill(['status' => 'disconnected', 'status_at' => now(), 'phone' => null, 'last_probe_ok_at' => null])->save();
        $channel->logEvent('logout');

        return ['ok' => true];
    }

    /** حذف الجلسة ومفتاحها نهائياً من البوابة (المحادثات تبقى في نُسك) */
    public static function destroy(WaChannel $channel): array
    {
        if (!$channel->openwa_session_id) {
            return ['ok' => true];
        }
        $res = OpenWaClient::admin()->request('DELETE', '/sessions/'.rawurlencode((string) $channel->openwa_session_id));
        if (!$res->ok && $res->status() !== 404) {
            return self::failed('destroy', $res);
        }
        $channel->forceFill(['openwa_session_id' => null, 'api_key' => null, 'webhook_id' => null, 'webhook_secret' => null,
            'status' => 'created', 'status_at' => now(), 'phone' => null, 'is_active' => false])->save();
        $channel->logEvent('destroyed');

        return ['ok' => true];
    }

    /**
     * QR الربط: من الكاش إن وصل بحدث session.qr (60 ثانية)، وإلا من البوابة.
     *
     * @return array{ok: bool, qr?: ?string, status?: string, code?: string, message?: string}
     */
    public static function qr(WaChannel $channel): array
    {
        if ($cached = Cache::get(self::qrKey($channel))) {
            return ['ok' => true, 'qr' => (string) $cached, 'status' => (string) $channel->status];
        }
        $res = OpenWaClient::for($channel)->session('GET', '/qr');
        if (!$res->ok) {
            // 400: لم يجهز QR بعد أو الجلسة مربوطة أو متوقفة. المتوقفة (تهيئة انتهت مهلتها بعد إعادة تشغيل البوابة) لا
            // يأتيها رمز أبداً: تُشغَّل في الخلفية مرة كل 90 ثانية، فنافذة الربط لا تنتظر بلا نهاية
            if ($res->status() === 400 && in_array((string) $channel->status, ['disconnected', 'failed', 'created'], true)
                && Cache::add('wa:openwa:qr-start:'.$channel->id, 1, 90)) {
                $id = (int) $channel->id;
                dispatch(function () use ($id) {
                    if ($c = WaChannel::find($id)) {
                        self::start($c);
                    }
                })->onQueue('wa-system');
            }

            return $res->status() === 400 ? ['ok' => true, 'qr' => null, 'status' => (string) $channel->status] : self::failed('qr', $res);
        }
        $qr = (string) $res->json('qrCode', '');

        return ['ok' => true, 'qr' => $qr !== '' ? $qr : null, 'status' => (string) $res->json('status', $channel->status)];
    }

    /** @return array{ok: bool, code?: string, pairing_code?: string, message?: string} */
    public static function pairingCode(WaChannel $channel, string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 8) {
            return self::fail('BAD_PHONE', 'اكتب رقم الهاتف كاملاً بالمقدمة الدولية.');
        }
        $res = OpenWaClient::for($channel)->session('POST', '/pairing-code', ['phoneNumber' => $digits], 'start');
        $code = (string) $res->json('pairingCode', '');

        return $res->ok && $code !== '' ? ['ok' => true, 'pairing_code' => $code] : self::failed('pairing', $res);
    }

    /** يقرأ حالة الجلسة ورقمها من البوابة ويحفظهما (بلا حدث) */
    public static function refresh(WaChannel $channel): ?array
    {
        $res = OpenWaClient::for($channel)->session('GET', '');
        if (!$res->ok) {
            return null;
        }
        $data = (array) $res->json();
        ChannelState::applyStatus($channel, (string) ($data['status'] ?? $channel->status), [
            'phone' => $data['phone'] ?? null, 'pushName' => $data['pushName'] ?? null,
        ], quiet: true);

        return $data;
    }

    public static function qrKey(WaChannel $channel): string
    {
        return 'wa:openwa:qr:'.$channel->id;
    }

    private static function issueKey(WaChannel $channel): array
    {
        $res = OpenWaClient::admin()->request('POST', '/auth/api-keys', [
            'name' => 'nuskjo-ch-'.$channel->id.'-'.Str::lower(Str::random(6)),
            'role' => 'operator',
            'allowedSessions' => [(string) $channel->openwa_session_id],
        ]);
        $key = (string) $res->json('apiKey', '');
        if (!$res->ok || $key === '') {
            return self::failed('api_key', $res);
        }
        $channel->forceFill(['api_key' => $key])->save();
        $channel->logEvent('api_key');

        return ['ok' => true];
    }

    private static function sessionName(WaChannel $channel): string
    {
        return 'nuskjo-'.$channel->id.'-'.substr(str_replace('-', '', (string) $channel->uuid), 0, 8);
    }

    private static function failed(string $step, OpenWaResponse $res): array
    {
        $code = $res->error?->code ?? 'GW_ERROR';
        WaLog::warning('openwa.session.failed', ['event' => $step, 'error_code' => $code, 'http' => $res->status()]);
        $msg = $res->error?->toMetaError()->staffMessage ?? 'تعذّر تنفيذ الأمر على بوابة واتساب.';

        return self::fail($code, $msg);
    }

    private static function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
