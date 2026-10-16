<?php

namespace App\Services\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaHistoryChunk;
use App\Support\FeatureFlags;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * حفظ سجل التعايش السابق بانتظار القرار (C012، العلم coexistence_history_hold). الحمولة تصل مرة واحدة بعد الربط، فإن
 * لم تُحفظ ضاعت. تُحفظ قيمة الحقل history وحدها مشفّرة بـ APP_KEY مدة whatsapp.coexistence.history_hold_days ثم تُمحى
 * كل ساعة (prune). لا عرض ولا تصدير: الأمر whatsapp:coexistence-history يلخّص ويحلّل على دفعات ويهمل، والاستيراد نفسه
 * إلى الصندوق ملحمة مستقلة بقرار المالك (تصفية ونطاق زمني). whatsapp:forget يحذف خيوط العميل من المحفوظ.
 */
final class HistoryHold
{
    private static ?bool $available = null;

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('wa_history_chunks');
    }

    public static function enabled(): bool
    {
        return self::available() && FeatureFlags::on('coexistence_history_hold');
    }

    /** @return ?Carbon موعد المحو، أو null إن كانت الدفعة نفسها محفوظة من قبل */
    public static function keep(array $value, array $counts): ?Carbon
    {
        $until = now()->addDays(max(1, (int) config('whatsapp.coexistence.history_hold_days', 30)));
        try {
            WaHistoryChunk::create([
                'sha256' => hash('sha256', (string) json_encode($value)),
                'phase' => $counts['phase'],
                'chunk_order' => $counts['chunk_order'],
                'progress' => $counts['progress'],
                'threads' => $counts['threads'],
                'messages' => $counts['messages'],
                'status' => WaHistoryChunk::HELD,
                'payload' => $value,
                'received_at' => now(),
                'purge_after' => $until,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $until;
    }

    /** @return array{held: int, threads: int, messages: int, purge_soonest: ?string, imported: int, discarded: int, expired: int} أعداد وحدها */
    public static function summary(): array
    {
        if (!self::available()) {
            return ['held' => 0, 'threads' => 0, 'messages' => 0, 'purge_soonest' => null, 'imported' => 0, 'discarded' => 0, 'expired' => 0];
        }
        $held = WaHistoryChunk::where('status', WaHistoryChunk::HELD);

        return [
            'held' => (clone $held)->count(),
            'threads' => (int) (clone $held)->sum('threads'),
            'messages' => (int) (clone $held)->sum('messages'),
            'purge_soonest' => (clone $held)->min('purge_after'),
            'imported' => WaHistoryChunk::where('status', WaHistoryChunk::IMPORTED)->count(),
            'discarded' => WaHistoryChunk::where('status', WaHistoryChunk::DISCARDED)->count(),
            'expired' => WaHistoryChunk::where('status', WaHistoryChunk::EXPIRED)->count(),
        ];
    }

    /**
     * تحليل المحفوظ على دفعات (مدخل قرار الاستيراد): كم خيطاً ورسالة، ومدى التواريخ، وكم خيطاً لعميل له محادثة عندنا،
     * وكم رسالة منا (من هاتف الوكالة) ومن العملاء، وأنواعها. أعداد وتواريخ وحدها، بلا رقم ولا نص.
     *
     * @return array{chunks: int, threads: int, messages: int, existing_threads: int, from_business: int, from_customers: int, types: array<string, int>, oldest: ?string, newest: ?string}
     */
    public static function analyze(string $businessPhone = ''): array
    {
        $out = ['chunks' => 0, 'threads' => 0, 'messages' => 0, 'existing_threads' => 0, 'from_business' => 0, 'from_customers' => 0,
            'types' => [], 'oldest' => null, 'newest' => null];
        if (!self::available()) {
            return $out;
        }
        $ours = PhoneNormalizer::digits($businessPhone);
        $min = null;
        $max = null;
        WaHistoryChunk::where('status', WaHistoryChunk::HELD)->whereNotNull('payload')->select(['id', 'payload'])
            ->chunkById(20, function ($rows) use (&$out, &$min, &$max, $ours) {
                foreach ($rows as $row) {
                    $out['chunks']++;
                    foreach (self::threads((array) $row->payload) as $thread) {
                        $out['threads']++;
                        $id = (string) data_get($thread, 'id', '');
                        $phone = ContactIdentity::phoneFrom($id);
                        if (($phone && WaConversation::where('phone', $phone)->exists()) || ($id !== '' && WaConversation::where('wa_user_id', $id)->exists())) {
                            $out['existing_threads']++;
                        }
                        foreach ((array) data_get($thread, 'messages', []) as $m) {
                            $out['messages']++;
                            $type = WebhookFields::name(data_get($m, 'type') ?: 'text');
                            $out['types'][$type] = ($out['types'][$type] ?? 0) + 1;
                            $from = PhoneNormalizer::digits((string) data_get($m, 'from', ''));
                            $ours !== '' && $from === $ours ? $out['from_business']++ : $out['from_customers']++;
                            $ts = data_get($m, 'timestamp');
                            if (is_numeric($ts) && (int) $ts > 0) {
                                $min = $min === null ? (int) $ts : min($min, (int) $ts);
                                $max = $max === null ? (int) $ts : max($max, (int) $ts);
                            }
                        }
                    }
                }
            });
        arsort($out['types']);
        $out['oldest'] = $min !== null ? Carbon::createFromTimestamp($min)->setTimezone('Asia/Amman')->format('Y-m-d') : null;
        $out['newest'] = $max !== null ? Carbon::createFromTimestamp($max)->setTimezone('Asia/Amman')->format('Y-m-d') : null;

        return $out;
    }

    /** إهمال المحفوظ كله بقرار (الجسم يُمحى، ويبقى الأثر بالأعداد) */
    public static function discard(): int
    {
        return self::available() ? self::settle(WaHistoryChunk::where('status', WaHistoryChunk::HELD), WaHistoryChunk::DISCARDED) : 0;
    }

    /** ما مضت مدته يُمحى (كل ساعة) */
    public static function prune(): int
    {
        return self::available()
            ? self::settle(WaHistoryChunk::where('status', WaHistoryChunk::HELD)->where('purge_after', '<=', now()), WaHistoryChunk::EXPIRED)
            : 0;
    }

    /**
     * المحو (whatsapp:forget): خيوط العميل (برقمه أو معرّفه في أي حقل من الخيط) تُحذف من كل دفعة محفوظة. يُفك في الذاكرة
     * ولا يُكتب في أي مكان. $write=false للخطة: العدد وحده.
     *
     * @param  list<string>  $needles
     */
    public static function forget(array $needles, bool $write = true): int
    {
        $needles = array_values(array_filter($needles, fn ($v) => is_string($v) && strlen($v) >= 5));
        if ($needles === [] || !self::available()) {
            return 0;
        }
        $removed = 0;
        WaHistoryChunk::where('status', WaHistoryChunk::HELD)->whereNotNull('payload')->chunkById(20, function ($rows) use ($needles, $write, &$removed) {
            foreach ($rows as $row) {
                $payload = (array) $row->payload;
                $gone = 0;
                foreach ((array) ($payload['history'] ?? []) as $i => $item) {
                    $threads = (array) data_get($item, 'threads', []);
                    $kept = array_values(array_filter($threads, function ($thread) use ($needles) {
                        $json = (string) json_encode($thread);
                        foreach ($needles as $needle) {
                            if (str_contains($json, $needle)) {
                                return false;
                            }
                        }

                        return true;
                    }));
                    $gone += count($threads) - count($kept);
                    if (is_array($item)) {
                        $payload['history'][$i]['threads'] = $kept;
                    }
                }
                if ($gone > 0 && $write) {
                    $c = CoexistenceSync::historyCounts($payload);
                    $row->forceFill(['payload' => $payload, 'threads' => $c['threads'], 'messages' => $c['messages']])->save();
                }
                $removed += $gone;
            }
        });

        return $removed;
    }

    /** @return list<array> خيوط الدفعة */
    private static function threads(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['history'] ?? []) as $item) {
            foreach ((array) data_get($item, 'threads', []) as $thread) {
                if (is_array($thread)) {
                    $out[] = $thread;
                }
            }
        }

        return $out;
    }

    private static function settle($query, string $status): int
    {
        return $query->update(['status' => $status, 'payload' => null, 'settled_at' => now(), 'updated_at' => now()]);
    }
}
