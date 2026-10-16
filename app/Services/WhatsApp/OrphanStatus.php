<?php

namespace App\Services\WhatsApp;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * «الإيصال اليتيم» (E21): حالة من ميتا لرسالة لم نحفظ معرّفها بعد (ولا تطابقها biz_opaque_callback_data، ومنها failed
 * التي لا تحمله) تُحفظ في الكاش دقائق، وتُطبَّق لحظة كتابة wamid على الصف بعد ردّ ميتا (WhatsAppChannel).
 *
 * المفتاح wa:orphan-status:{wamid}: معرّف من ميتا لا رقم ولا نص. القيمة 5 عناصر على الأكثر {status, ts, errors[0]
 * مختصرة ومقنّعة}. المخزن الافتراضي database مشترك بين php-fpm والعامل، والمنتهي يحذفه ops:prune-cache يومياً (E13.7).
 */
final class OrphanStatus
{
    public const PREFIX = 'wa:orphan-status:';

    public const MAX = 5;

    public static function remember(string $wamid, array $st): void
    {
        $key = self::PREFIX.$wamid;
        $item = self::compact($st);
        $put = function () use ($key, $item) {
            $list = (array) Cache::get($key, []);
            foreach ($list as $old) {
                if (($old['status'] ?? null) === $item['status'] && ($old['ts'] ?? null) === $item['ts']) {
                    return;   // إعادة ميتا للحالة نفسها
                }
            }
            $list[] = $item;
            Cache::put($key, array_slice($list, -self::MAX), now()->addSeconds(self::ttl()));
        };

        // حالتان للرسالة نفسها في اللحظة نفسها (sent وdelivered): القفل يمنع أن تمحو إحداهما الأخرى
        try {
            Cache::lock('wa:orphan-lock:'.$wamid, 5)->block(3, $put);
        } catch (LockTimeoutException) {
            $put();
        }
        WaLog::info('wa.status.orphan', ['wamid' => $wamid, 'status' => $item['status']]);
    }

    /**
     * يُستدعى بعد كتابة wamid على صفه: يطبّق ما انتظر بترتيب وقت ميتا، ثم يُحذف المفتاح. لا يرمي أبداً.
     *
     * @return int عدد الحالات المطبّقة
     */
    public static function drain(string $wamid): int
    {
        if ($wamid === '' || !StatusApplier::enabled()) {
            return 0;
        }
        try {
            $list = (array) Cache::pull(self::PREFIX.$wamid, []);
        } catch (\Throwable $e) {
            WaLog::error('wa.status.orphan_failed', ['wamid' => $wamid, 'type' => class_basename($e)]);

            return 0;
        }
        usort($list, fn ($a, $b) => ((int) ($a['ts'] ?? 0)) <=> ((int) ($b['ts'] ?? 0)));

        $applied = 0;
        foreach ($list as $item) {
            try {
                StatusApplier::apply(array_filter([
                    'id' => $wamid,
                    'status' => $item['status'] ?? null,
                    'timestamp' => $item['ts'] ?? null,
                    'errors' => isset($item['error']) ? [$item['error']] : null,
                ], fn ($v) => $v !== null), fromOrphan: true);
                $applied++;
            } catch (\Throwable $e) {
                WaLog::error('wa.status.orphan_failed', ['wamid' => $wamid, 'type' => class_basename($e)]);
            }
        }
        if ($applied > 0) {
            WaLog::info('wa.status.orphan_applied', ['wamid' => $wamid, 'count' => $applied]);
        }

        return $applied;
    }

    public static function ttl(): int
    {
        return max(60, (int) config('whatsapp.status.orphan_ttl', 600));
    }

    /** @return array{status: string, ts: ?int, error?: array} ما يكفي لإعادة التطبيق: الرمز والعنوان والتفاصيل مقنّعة */
    private static function compact(array $st): array
    {
        $ts = data_get($st, 'timestamp');
        $item = ['status' => (string) data_get($st, 'status', ''), 'ts' => is_numeric($ts) ? (int) $ts : null];
        $error = data_get($st, 'errors.0');
        if (is_array($error)) {
            $item['error'] = array_filter([
                'code' => is_numeric(data_get($error, 'code')) ? (int) data_get($error, 'code') : null,
                'title' => is_scalar(data_get($error, 'title')) ? mb_substr((string) data_get($error, 'title'), 0, 120) : null,
                'error_data' => ($d = MetaErrorCatalog::maskDetails(is_scalar(data_get($error, 'error_data.details')) ? (string) data_get($error, 'error_data.details') : null)) !== null
                    ? ['details' => $d] : null,
            ], fn ($v) => $v !== null);
        }

        return $item;
    }
}
