<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WaAccountEvent;
use App\Models\WaBotSetting;
use App\Models\WaMessage;
use App\Services\Ops\OpsAlerts;
use App\Support\FeatureFlags;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * مزامنة التعايش (E39): history (سجل المحادثات السابق حتى 180 يوماً) وsmb_app_state_sync (جهات اتصال التطبيق).
 * تصلان مرة واحدة بعد الربط بدقائق ولا تتكرران (C012). لا يُستورد شيء افتراضياً، حفاظاً على الخصوصية والحجم:
 * - history: صف في wa_account_events بالأعداد وحدها (المحادثات والرسائل والمرحلة والتقدّم) وتنبيه للمدير. ومع العلم
 *   coexistence_history_hold تُحفظ القيمة مشفّرة بانتظار قرار الاستيراد مدة محدودة ثم تُمحى (HistoryHold).
 * - smb_app_state_sync: العدد بحسب الفعل وحده. الأسماء لا تُقرأ (غير موثوقة، E11) ولا تُحدَّث منها أي محادثة.
 * تعمل مع أي علم تعايش، وبدونها سلوك E20 (تنبيه فوري). لرقمنا وحده (metadata.phone_number_id).
 */
final class CoexistenceSync
{
    public static function active(): bool
    {
        return EchoHandler::active() || FeatureFlags::on('coexistence_history_hold');
    }

    public static function handle(string $field, array $value, string $entryId, int $entryTime, string $ownId): void
    {
        WebhookFields::seen($field);
        $pnid = (string) data_get($value, 'metadata.phone_number_id', '');
        if ($ownId === '' || $pnid !== $ownId) {
            WaLog::warning('coexistence.sync_foreign', ['field' => $field]);

            return;
        }

        $field === 'history' ? self::history($value, $entryId, $entryTime, $pnid) : self::stateSync($value, $entryId, $entryTime, $pnid);
    }

    private static function history(array $value, string $entryId, int $entryTime, string $pnid): void
    {
        $c = self::historyCounts($value);
        $fingerprint = self::fingerprint('history', $entryId, $entryTime, $value);
        if (self::seen($fingerprint)) {
            WaLog::info('coexistence.history_duplicate', ['chunk' => $c['chunk_order']]);

            return;
        }

        $until = null;
        if ($c['messages'] > 0 && HistoryHold::enabled()) {
            $until = HistoryHold::keep($value, $c);
        }
        $event = $c['error_codes'] !== [] ? 'HISTORY_DECLINED' : 'HISTORY_SYNC';
        self::record('history', $event, 'alert', $entryId, $entryTime, $pnid, $fingerprint, $c + ['held' => $until !== null]);
        WaLog::warning('coexistence.history', ['event' => $event, 'count' => $c['messages'], 'chunk' => $c['chunk_order'], 'mode' => $until ? 'held' : 'counted']);

        $body = $event === 'HISTORY_DECLINED'
            ? 'مشاركة السجل السابق مطفأة من تطبيق الأعمال، فلم يصل شيء.'
            : "{$c['threads']} محادثة و{$c['messages']} رسالة (الدفعة {$c['chunk_order']}، التقدّم {$c['progress']}%). "
                .($until !== null
                    ? 'حُفظت مشفّرة بانتظار قرار الاستيراد حتى '.$until->copy()->setTimezone('Asia/Amman')->format('Y-m-d').' (whatsapp:coexistence-history).'
                    : 'لم تُستورد ولم تُحفظ (الخصوصية افتراضياً).');
        app(OpsAlerts::class)->notifyAdmins('wa:coexistence:history', '📚 وصل سجل المحادثات السابق من تطبيق الأعمال', $body, ChannelHealth::URL);
    }

    private static function stateSync(array $value, string $entryId, int $entryTime, string $pnid): void
    {
        $items = array_values(array_filter((array) data_get($value, 'state_sync', []), 'is_array'));
        $fingerprint = self::fingerprint('smb_app_state_sync', $entryId, $entryTime, $value);
        if (self::seen($fingerprint)) {
            return;
        }
        $actions = array_count_values(array_map(fn ($i) => WebhookFields::name($i['action'] ?? '-'), $items));
        $types = array_count_values(array_map(fn ($i) => WebhookFields::name($i['type'] ?? '-'), $items));
        self::record('smb_app_state_sync', 'STATE_SYNC', 'none', $entryId, $entryTime, $pnid, $fingerprint,
            ['total' => count($items), 'actions' => $actions, 'types' => $types]);
        WaLog::info('coexistence.state_sync', ['count' => count($items)]);
    }

    /** بطاقة «📱 التعايش»: الأعلام والحامل ومرشّحوه ومدة الإيقاف والأعداد وآخر مزامنة وحالة الربط. بلا رقم ولا نص */
    public static function panel(): array
    {
        $s = WaBotSetting::current();
        $holder = (int) $s->coexistence_holder_user_id;
        $candidates = User::with('role.permissions')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role_id'])
            ->filter(fn ($u) => $u->can('whatsapp.view_all'))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all();
        $today = Carbon::now('Asia/Amman')->startOfDay()->setTimezone(config('app.timezone'));
        $last = fn (string $field) => self::hasTable() ? WaAccountEvent::where('field', $field)->orderByDesc('id')->first(['event', 'payload', 'created_at']) : null;
        $history = $last('history');
        $sync = $last('smb_app_state_sync');

        return [
            'flags' => ['guard' => EchoHandler::guardOn(), 'echoes' => EchoHandler::fullOn(), 'history_hold' => FeatureFlags::on('coexistence_history_hold')],
            'holder_id' => $holder ?: null,
            'holder_valid' => $holder > 0 && EchoHandler::holderId() === $holder,
            'candidates' => $candidates,
            'echo_pause_minutes' => $s->echo_pause_minutes,
            'pause_minutes' => (int) $s->pause_minutes,
            'echoes_today' => WaMessage::where('source', 'staff_phone')->where('created_at', '>=', $today)->count(),
            'history' => [
                'last' => $history ? ['event' => $history->event, 'at' => $history->created_at?->toIso8601String(),
                    'threads' => (int) data_get($history->payload, 'threads', 0), 'messages' => (int) data_get($history->payload, 'messages', 0)] : null,
                'hold' => HistoryHold::summary(),
                'hold_days' => (int) config('whatsapp.coexistence.history_hold_days', 30),
            ],
            'state_sync' => $sync ? ['at' => $sync->created_at?->toIso8601String(), 'total' => (int) data_get($sync->payload, 'total', 0)] : null,
            'link' => data_get(WaBotSetting::current()->wa_health_meta, 'coexistence'),
        ];
    }

    /**
     * أعداد history بلا أي محتوى: المحادثات والرسائل، ومرحلة الدفعة وترتيبها وتقدّمها، ورموز الخطأ (رفض المشاركة).
     *
     * @return array{phase: ?int, chunk_order: ?int, progress: ?int, threads: int, messages: int, error_codes: list<int>}
     */
    public static function historyCounts(array $value): array
    {
        $out = ['phase' => null, 'chunk_order' => null, 'progress' => null, 'threads' => 0, 'messages' => 0, 'error_codes' => []];
        foreach ((array) data_get($value, 'history', []) as $item) {
            foreach (['phase', 'chunk_order', 'progress'] as $key) {
                $v = data_get($item, "metadata.{$key}");
                if (is_numeric($v)) {
                    $out[$key] = (int) $v;
                }
            }
            foreach ((array) data_get($item, 'threads', []) as $thread) {
                $out['threads']++;
                $out['messages'] += count((array) data_get($thread, 'messages', []));
            }
            foreach ((array) data_get($item, 'errors', []) as $error) {
                if (is_numeric(data_get($error, 'code'))) {
                    $out['error_codes'][] = (int) data_get($error, 'code');
                }
            }
        }

        return $out;
    }

    /** البصمة على القيمة كاملة (الدفعات تختلف بمحتواها)، ولا يُخزَّن منها إلا الهاش */
    private static function fingerprint(string $field, string $entryId, int $entryTime, array $value): string
    {
        return AccountEventHandler::fingerprint($field, $entryId, $entryTime, ['sha' => hash('sha256', (string) json_encode($value))]);
    }

    private static ?bool $hasTable = null;

    private static function hasTable(): bool
    {
        return self::$hasTable ??= Schema::hasTable('wa_account_events');
    }

    private static function seen(string $fingerprint): bool
    {
        return self::hasTable() && WaAccountEvent::where('fingerprint', $fingerprint)->exists();
    }

    /** صف معالَج بالأعداد وحدها في سجل أحداث الحساب (E22)، معلوماتي (يُقلَّم بعد 180 يوماً) */
    private static function record(string $field, string $event, string $effect, string $entryId, int $entryTime, string $pnid, string $fingerprint, array $payload): void
    {
        if (!self::hasTable()) {
            return;
        }
        WaAccountEvent::insertOrIgnore([
            'field' => $field,
            'event' => $event,
            'entry_id' => $entryId !== '' ? mb_substr($entryId, 0, 32) : null,
            'phone_number_id' => mb_substr($pnid, 0, 64),
            'severity' => 'info',
            'effect' => $effect,
            'fingerprint' => $fingerprint,
            'occurred_at' => $entryTime > 0 ? Carbon::createFromTimestamp($entryTime) : now(),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'handled_at' => now(),
            'created_at' => now(),
        ]);
    }
}
