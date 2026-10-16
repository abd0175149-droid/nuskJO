<?php

namespace App\Services\WhatsApp;

use App\Models\WaBotSetting;
use App\Services\Ops\OpsAlerts;
use App\Services\Ops\OpsHealth;
use App\Support\FeatureFlags;

/**
 * سياج الويبهوك (E20): الويبهوك واحد للتطبيق، وقد يحمل حساب أعمال آخر أو رقماً آخر على التطبيق نفسه.
 * يُسقط entry بمعرّف حساب (WABA) غير حسابنا، وchange يحمل phone_number_id غير رقمنا أو بلا رقم.
 *
 * وضعان (العلم webhook_fence_enforce):
 * - تسجيل (الافتراضي): لا يُسقط إلا ما تسقطه E04 أصلاً داخل handleWebhook، ويُعدّ الباقي would_reject.
 * - فرض: يُسقط فعلاً، ويُعدّ المرفوض حسب السبب، وإشعار للمدراء عند أول رفض في اليوم.
 * المرفوض بيانات جهة أخرى: لا يُخزَّن محتواه أبداً، ولا يتغير رد 200 لميتا بسببه.
 */
final class WebhookFence
{
    public const REASONS = ['waba_mismatch', 'pnid_missing', 'pnid_mismatch', 'channel_unconfigured'];

    /** حقول تحمل metadata.phone_number_id للرقم المستقبِل */
    public const PNID_FIELDS = ['messages', 'calls', 'smb_message_echoes', 'history', 'smb_app_state_sync', 'user_id_update'];

    public static function enforcing(): bool
    {
        return FeatureFlags::on('webhook_fence_enforce');
    }

    /**
     * معرّف الحساب المفروض: WA_WABA_ID من البيئة، وإلا المحفوظ يدوياً من الإعدادات. المتعلَّم من entry.id
     * يُعرض فقط ولا يفرض رفضاً (قد يتعلّم من حمولة أولى خاطئة).
     *
     * @return array{id: ?string, source: ?string} source: env | manual | null
     */
    public static function account(?WaBotSetting $s = null): array
    {
        $env = (string) config('services.whatsapp.waba_id', '');
        if ($env !== '') {
            return ['id' => $env, 'source' => 'env'];
        }
        $s ??= WaBotSetting::current();
        if ($s->wa_waba_id_source === 'manual' && (string) $s->wa_waba_id !== '') {
            return ['id' => (string) $s->wa_waba_id, 'source' => 'manual'];
        }

        return ['id' => null, 'source' => null];
    }

    /**
     * @return array{payload: array, rejected: list<array{reason: string, field: string}>, emptied: bool}
     *                                                                                                    emptied: الفرض أسقط كل ما في الحمولة (صفها rejected بلا جسم)
     */
    public static function filter(array $payload): array
    {
        $settings = WaBotSetting::current();
        $waba = self::account($settings)['id'];
        $ownPnid = (string) $settings->phoneNumberId();
        $enforce = self::enforcing();
        $rejected = [];
        $entries = [];
        $had = false;

        foreach ((array) data_get($payload, 'entry', []) as $entry) {
            $had = true;
            $entryId = (string) data_get($entry, 'id', '');
            if ($waba !== null && $entryId !== '' && $entryId !== $waba) {
                foreach ((array) data_get($entry, 'changes', []) ?: [[]] as $change) {
                    $rejected[] = ['reason' => 'waba_mismatch', 'field' => self::field($change)];
                }
                if (!$enforce) {
                    // تسجيل: يمر كما هو (تحكم فيه قواعد E04)، بلا عدّ ثانٍ لأسباب الرقم
                    $entries[] = $entry;
                }

                continue;
            }

            $changes = [];
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $reason = self::pnidReason($change, $ownPnid);
                if ($reason !== null) {
                    $rejected[] = ['reason' => $reason, 'field' => self::field($change)];
                    if ($enforce) {
                        continue;
                    }
                }
                $changes[] = $change;
            }
            if (is_array($entry)) {
                $entry['changes'] = $changes;
            }
            $entries[] = $entry;
        }

        if ($rejected !== []) {
            self::record($rejected, $enforce);
        }
        $payload['entry'] = $entries;
        $kept = array_sum(array_map(fn ($e) => count((array) data_get($e, 'changes', [])), $entries));

        return ['payload' => $payload, 'rejected' => $enforce ? $rejected : [], 'emptied' => $enforce && $had && $kept === 0 && $rejected !== []];
    }

    /** E04: حدث جودة لرقم معروض آخر، أو حدث حساب لحساب آخر، يُتجاهل (لا يوقف إرسالنا) */
    public static function otherAccountEvent(string $field, array $value, string $entryId, WaBotSetting $settings): bool
    {
        if ($field === 'phone_number_quality_update') {
            $ours = (string) $settings->wa_display_phone;
            $theirs = PhoneNormalizer::digits((string) data_get($value, 'display_phone_number', ''));

            return $ours !== '' && $theirs !== '' && $theirs !== $ours;
        }
        // المفروض أولاً، وإلا المتعلَّم من رسائل رقمنا (سلوك E04 في وضع التسجيل)
        $account = self::account($settings)['id'] ?? (string) $settings->wa_waba_id;

        return $account !== '' && $entryId !== '' && $entryId !== $account;
    }

    private static function pnidReason(mixed $change, string $ownPnid): ?string
    {
        $field = self::field($change);
        if (!in_array($field, self::PNID_FIELDS, true)) {
            return null;
        }
        $pnid = (string) data_get($change, 'value.metadata.phone_number_id', '');
        // user_id_update وما شابهه بلا metadata لا يُحكم عليه بالرقم (لا يحمله دائماً)
        if ($field !== 'messages' && !is_array(data_get($change, 'value.metadata'))) {
            return null;
        }

        return match (true) {
            $ownPnid === '' => 'channel_unconfigured',
            $pnid === '' => 'pnid_missing',
            $pnid !== $ownPnid => 'pnid_mismatch',
            default => null,
        };
    }

    private static function field(mixed $change): string
    {
        return WebhookFields::name(data_get($change, 'field'));
    }

    /** عدّاد يومي لكل سبب (would_ في التسجيل)، وإشعار واحد في اليوم لكل سبب عند الفرض. لا محتوى */
    private static function record(array $rejected, bool $enforce): void
    {
        $store = OpsHealth::store();
        $day = now()->format('Ymd');
        foreach (array_count_values(array_column($rejected, 'reason')) as $reason => $n) {
            $key = $enforce ? "wa:fence:{$reason}:{$day}" : "wa:fence:would:{$reason}:{$day}";
            $store->add($key, 0, now()->addDays(15));
            $store->increment($key, $n);
            WaLog::warning($enforce ? 'webhook.fence.rejected' : 'webhook.fence.would_reject', ['reason' => $reason, 'count' => $n]);

            if ($enforce && $store->add("wa:fence:alerted:{$reason}:{$day}", 1, now()->addDays(2))) {
                app(OpsAlerts::class)->notifyAdmins('wa:fence:'.$reason, '⛔ رُفضت حمولة ويبهوك',
                    "السبب: {$reason}. إن كان WA_WABA_ID أو معرّف الرقم خاطئاً فقد تُرفض رسائل العملاء — راجع الإعدادات فوراً.");
            }
        }
    }

    /** @return array<string, array{rejected: int, would: int}> لآخر يومين (بطاقة الإعدادات وdoctor) */
    public static function counts(): array
    {
        $store = OpsHealth::store();
        $days = [now()->format('Ymd'), now()->subDay()->format('Ymd')];
        $out = [];
        foreach (self::REASONS as $reason) {
            $out[$reason] = ['rejected' => 0, 'would' => 0];
            foreach ($days as $d) {
                $out[$reason]['rejected'] += (int) $store->get("wa:fence:{$reason}:{$d}", 0);
                $out[$reason]['would'] += (int) $store->get("wa:fence:would:{$reason}:{$d}", 0);
            }
        }

        return $out;
    }
}
