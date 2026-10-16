<?php

namespace App\Services\WhatsApp\Search;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\WhatsApp\WaLog;
use App\Support\AuditSampler;
use Illuminate\Support\Facades\Cache;

/**
 * تدقيق البحث في الرسائل (E30.4) بلا نص الاستعلام (قد يكون رقم جواز أو هاتفاً): صف واحد لكل موظف كل 10 دقائق مع
 * suppressed_count، فيه الطول وبصمة 12 محرفاً تكفي لمطابقة شكوى محددة، وأسماء الفلاتر، وعدد النتائج.
 * والبحث الكثيف (20 فأكثر في كل دقيقة من 3 دقائق متتالية) يكتب سطراً بخطورة warn مرة كل 10 دقائق: استكشاف جماعي.
 */
final class SearchAudit
{
    /** @param  list<string>  $filters  أسماء الفلاتر المضبوطة لا قيمها */
    public static function record(User $user, array $result, array $filters): void
    {
        $changes = ['q_len' => $result['q_len'], 'q_fp' => $result['q_fp'], 'filters' => $filters, 'hits' => $result['total'],
            'capped' => $result['total_capped'], 'engine' => $result['engine']];
        $window = 60 * max(1, (int) config('whatsapp.search.audit_minutes', 10));

        try {
            $sample = AuditSampler::allow(AuditAction::WaMessageSearch->value, 'u:'.$user->id, 1, $window);
        } catch (\Throwable) {
            $sample = ['allowed' => false, 'suppressed' => 0];
        }
        if ($sample['allowed']) {
            AuditLog::safe(AuditAction::WaMessageSearch, 'wa_search', null, null,
                $changes + ($sample['suppressed'] > 0 ? ['suppressed_count' => $sample['suppressed']] : []));
        }

        self::burst($user, $changes);
    }

    private static function burst(User $user, array $changes): void
    {
        try {
            $store = Cache::store(config('audit.sampler_store', 'file'));
            $perMinute = max(1, (int) config('whatsapp.search.burst_per_minute', 20));
            $minutes = max(1, (int) config('whatsapp.search.burst_minutes', 3));
            $minute = intdiv(now()->getTimestamp(), 60);
            $key = fn (int $m) => "wa:search:rate:{$user->id}:{$m}";

            $store->add($key($minute), 0, 60 * ($minutes + 2));
            $count = (int) $store->increment($key($minute));
            if ($count !== $perMinute) {
                return;
            }
            for ($i = 1; $i < $minutes; $i++) {
                if ((int) $store->get($key($minute - $i), 0) < $perMinute) {
                    return;
                }
            }
            if ($store->add("wa:search:burst:{$user->id}", 1, 600)) {
                AuditLog::safe(AuditAction::WaMessageSearch, 'wa_search', null, null,
                    ['burst' => true, 'per_minute' => $perMinute, 'minutes' => $minutes] + $changes, AuditSeverity::Warn);
                WaLog::warning('wa.search.burst', ['count' => $perMinute * $minutes]);
            }
        } catch (\Throwable) {
            // التدقيق لا يُسقط البحث
        }
    }
}
