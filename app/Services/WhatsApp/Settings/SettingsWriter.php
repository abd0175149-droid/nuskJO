<?php

namespace App\Services\WhatsApp\Settings;

use App\Enums\AuditAction;
use App\Exceptions\StaleSettingsException;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الكاتب الوحيد لصف إعدادات البوت (E10). كل كتابة ترفع settings_version، فصفحة فُتحت قبلها يرد حفظها 409
 * بدل أن تلغي ما كُتب بعدها (مثل قفل وضعه حدث حساب من ميتا). أحداث التشغيل الصرفة (ما يتعلّمه الويبهوك،
 * ونتائج فحوص ميتا) تكتب بلا رفع النسخة وبلا تدقيق. ArchitectureSettingsTest يمنع أي كتابة على الصف خارج هذا الصنف.
 */
final class SettingsWriter
{
    /**
     * @param  array<string, mixed>  $changes  أسماء الأعمدة => القيم (tools_config يُدمج مفتاحاً بمفتاح، وnull يحذف المفتاح)
     * @param  ?int  $expectedVersion  null = بلا فحص تعارض (الكتّاب الآليون)
     * @param  string  $audit  strict (داخل المعاملة، فشله يلغي الكتابة) | safe (بعدها، لا يلغيها) | none
     * @param  array<string, mixed>  $meta  تُضاف إلى صف التدقيق (سبب، مصدر...)
     */
    public static function apply(array $changes, ?int $expectedVersion = null, ?AuditAction $action = AuditAction::WaSettingsUpdate,
        array $meta = [], bool $bumpVersion = true, string $audit = 'strict', ?string $label = null): WaBotSetting
    {
        $settings = WaBotSetting::current();
        $audited = $action !== null && $audit !== 'none';
        $before = $audited ? self::snapshot($settings) : [];
        $versioned = self::hasColumn('settings_version');

        $model = $settings->replicate()->setRawAttributes($settings->getAttributes(), true);
        if (array_key_exists('tools_config', $changes) && is_array($changes['tools_config'])) {
            $merged = $settings->tools_config ?? [];
            foreach ($changes['tools_config'] as $tool => $on) {
                // ما لا يُطفأ (handoff_to_human، E15.4) لا يُخزَّن له مفتاح أبداً
                if (!\App\Services\WhatsApp\BotTools::disableable((string) $tool)) {
                    continue;
                }
                if ($on === null) {
                    unset($merged[$tool]);
                } else {
                    $merged[$tool] = (bool) $on;
                }
            }
            $merged = array_filter($merged, fn ($tool) => \App\Services\WhatsApp\BotTools::disableable((string) $tool), ARRAY_FILTER_USE_KEY);
            $changes['tools_config'] = $merged ?: null;
        }
        $model->forceFill($changes);
        // عمود لم تُنشئه هجرته بعد (بين النشر والهجرة، أو نسخة مستعادة أقدم) لا يُسقط الإيقاف الآلي ولا الوضع الآمن
        $dirty = array_filter($model->getDirty(), fn ($column) => self::hasColumn($column), ARRAY_FILTER_USE_KEY);
        // تأكيد الطبقة المدفوعة لمزوّد الذكاء (E29) يخص المزوّد والمفتاح اللذين أُكّد عليهما: تغيير أيهما يمسحه
        if (array_intersect_key($dirty, ['provider' => 1, 'api_key' => 1]) !== [] && self::hasColumn('llm_terms_confirmed_at')) {
            $dirty = ['llm_terms_confirmed_at' => null, 'llm_terms_confirmed_by' => null]
                + (self::hasColumn('llm_terms_fp') ? ['llm_terms_fp' => null] : []) + $dirty;
        }
        $expectedVersion = $versioned ? $expectedVersion : null;
        $bumpVersion = $bumpVersion && $versioned;

        $logged = [];
        $result = DB::transaction(function () use ($settings, $dirty, $expectedVersion, $bumpVersion, $before, $action, $meta, $audit, $audited, $label, &$logged) {
            $query = DB::table('wa_bot_settings')->where('id', $settings->id)
                ->when($expectedVersion !== null, fn ($q) => $q->where('settings_version', $expectedVersion));

            if ($dirty === []) {
                // لا تغيير فعلي: لا كتابة ولا رفع، لكن النسخة القديمة تبقى تعارضاً
                if ($expectedVersion !== null && !$query->exists()) {
                    throw new StaleSettingsException((int) DB::table('wa_bot_settings')->where('id', $settings->id)->value('settings_version'));
                }

                return $settings->fresh();
            }

            $affected = $query->update($dirty + ['updated_at' => now()] + ($bumpVersion ? ['settings_version' => DB::raw('settings_version + 1')] : []));
            if ($affected === 0 && $expectedVersion !== null) {
                throw new StaleSettingsException((int) DB::table('wa_bot_settings')->where('id', $settings->id)->value('settings_version'));
            }

            $fresh = $settings->fresh();
            if ($audited) {
                $logged = self::rows($action, $fresh, SettingsSchema::auditDiff($before, self::snapshot($fresh)), $meta, $label);
                if ($audit === 'strict') {
                    foreach ($logged as $row) {
                        AuditLog::log(...$row);
                    }
                    $logged = [];
                }
            }

            return $fresh;
        });

        foreach ($logged as $row) {
            AuditLog::safe(...$row);
        }
        // المنقّح يحفظ الأسرار المعروفة؛ القاعدة تُكتب هنا بلا أحداث النموذج
        SecretRedactor::forget();

        return $result;
    }

    /**
     * إعادة تشفير أسرار محفوظة بالمفتاح الحالي (بعد تدوير APP_KEY مع APP_PREVIOUS_KEYS). القيم نفسها، فلا ترفع
     * النسخة؛ وapply لا يصلح هنا لأن القيمة غير «متغيّرة». ما لا يُفك أصلاً يُترك ويُعاد في unreadable.
     *
     * @param  list<string>  $columns
     * @return array{rewritten: list<string>, unreadable: list<string>}
     */
    public static function rewriteEncrypted(array $columns): array
    {
        $settings = WaBotSetting::current();
        $current = AppKeys::current();
        $updates = [];
        $unreadable = [];
        foreach ($columns as $column) {
            if (($settings->getAttributes()[$column] ?? null) === null) {
                continue;
            }
            try {
                $updates[$column] = $current->encryptString((string) $settings->{$column});
            } catch (DecryptException) {
                $unreadable[] = $column;
            }
        }
        if ($updates !== []) {
            DB::table('wa_bot_settings')->where('id', $settings->id)->update($updates);
            SecretRedactor::forget();
        }

        return ['rewritten' => array_keys($updates), 'unreadable' => $unreadable];
    }

    /** صف الفعل نفسه، وصف «تشغيل البوت أو إيقافه» مستقل إن تغيّر enabled ضمن غيره (للبحث عن «من أطفأ البوت؟») */
    private static function rows(AuditAction $action, WaBotSetting $fresh, array $diff, array $meta, ?string $label): array
    {
        $rows = [[$action, 'wa_bot_settings', $fresh->id, $label ?? 'إعدادات البوت', $diff + $meta]];
        if ($action !== AuditAction::WaBotEnabled && isset($diff['changed']['enabled'])) {
            $rows[] = [AuditAction::WaBotEnabled, 'wa_bot_settings', $fresh->id, 'بوت العملاء', ['on' => (bool) $fresh->enabled]];
        }

        return $rows;
    }

    /** @var array<string, bool>|null أعمدة الجدول، مرة لكل عملية */
    private static ?array $columns = null;

    private static function hasColumn(string $column): bool
    {
        self::$columns ??= array_fill_keys(Schema::getColumnListing('wa_bot_settings'), true);

        return isset(self::$columns[$column]);
    }

    /** للاختبارات والهجرات داخل العملية نفسها */
    public static function flushColumns(): void
    {
        self::$columns = null;
    }

    /** القيم كما يراها التدقيق (الأسرار مفكوكة لبصمتها فقط؛ ما لا يُفك يُعدّ فارغاً) */
    private static function snapshot(WaBotSetting $s): array
    {
        $out = [];
        foreach (array_keys(SettingsSchema::FIELDS) as $key) {
            try {
                $out[$key] = $s->{$key};
            } catch (DecryptException) {
                $out[$key] = null;
            }
        }

        return $out;
    }
}
