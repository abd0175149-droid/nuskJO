<?php

namespace App\Services\Integrations;

use App\Models\IntegrationEndpoint;
use App\Models\IntegrationOutboxRow;

/**
 * سطر التكاملات في بطاقة «صحة النظام والقناة» (E40.6): أعداد فقط، بلا روابط ولا حمولات. null حين لا شيء يُعرض
 * (العلم مطفأ ولا وجهات)، فلا يظهر السطر قبل الحاجة. لا يدخل /up: مستقبل خارجي معطّل لا يُسقط الحاوية.
 */
final class IntegrationsHealth
{
    /** @return array{enabled: bool, endpoints: int, active: int, paused: int, pending: int, oldest_pending_minutes: ?int, dead_24h: int}|null */
    public static function summary(): ?array
    {
        try {
            $enabled = IntegrationOutbox::enabled();
            $endpoints = IntegrationEndpoint::query()->count();
            if (!$enabled && $endpoints === 0) {
                return null;
            }
            $oldest = IntegrationOutboxRow::query()->where('status', IntegrationOutboxRow::PENDING)->min('created_at');

            return [
                'enabled' => $enabled,
                'endpoints' => $endpoints,
                'active' => IntegrationEndpoint::query()->where('is_active', true)->count(),
                'paused' => IntegrationEndpoint::query()->where('is_active', false)->whereNotNull('paused_reason')->count(),
                'pending' => IntegrationOutboxRow::query()->where('status', IntegrationOutboxRow::PENDING)->count(),
                'oldest_pending_minutes' => $oldest ? (int) now()->diffInMinutes($oldest, true) : null,
                'dead_24h' => IntegrationOutboxRow::query()->where('status', IntegrationOutboxRow::DEAD)->where('updated_at', '>=', now()->subDay())->count(),
            ];
        } catch (\Throwable) {
            return null;   // الجداول قبل الهجرة
        }
    }
}
