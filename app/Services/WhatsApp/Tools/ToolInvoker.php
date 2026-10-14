<?php

namespace App\Services\WhatsApp\Tools;

use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Services\WhatsApp\AdminTools;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\WaLog;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Validator;

/**
 * كل استدعاء أداة يطلبه النموذج يمر من هنا بالترتيب (E15.3): الاسم معلن في هذه الجولة، ثم الإذن عند التنفيذ
 * (الأداة مفعّلة للعميل، أو صلاحية رقم الإدارة)، ثم تطبيع المدخلات والتحقق منها، ثم التنفيذ بالمعالج المسجّل
 * (الهوية من $conv و$admin وحدهما)، ثم التصنيف ok أو denied أو failed، ثم سقف حجم النتيجة.
 * التحقق الصارم خلف العلم tool_validation_enforce؛ قبله يُسجَّل الفشل وتُقصّ القيم إلى حدودها ويستمر التنفيذ.
 */
final class ToolInvoker
{
    public const DENIED_NOTE = 'اعتذر بإيجاز ولا تذكر اسم الأداة.';

    /**
     * @param  list<string>  $declared  أسماء الأدوات المعلنة للنموذج في هذه الجولة
     * @return array{status: 'ok'|'denied'|'failed', output: array, bytes: int}
     */
    public static function run(string $name, array $in, WaConversation $conv, array $declared, ?WaAdminNumber $admin = null): array
    {
        $registry = $admin ? AdminTools::TOOLS : BotTools::TOOLS;

        // 1. اسم معلن لهذه الجولة (لا اسم مخترع ولا أداة أطفأها المالك بعد بناء الإعلان)
        if (!in_array($name, $declared, true) || !isset($registry[$name])) {
            return self::finish('denied', $name, ['ok' => false, 'error' => 'أداة غير متاحة', 'note' => self::DENIED_NOTE], $admin, $conv);
        }

        // 2. الإذن عند التنفيذ
        if (!$admin && !BotTools::isEnabled(WaBotSetting::current(), $name)) {
            return self::finish('denied', $name, ['ok' => false, 'error' => 'أداة غير متاحة', 'note' => self::DENIED_NOTE], $admin, $conv);
        }
        if ($admin) {
            $needed = AdminTools::permissionFor($name, (string) ($in['action'] ?? ''));
            if ($needed === null || !$admin->allowsAny((array) $needed)) {
                // AdminTools::execute يسجّل الرفض في wa_admin_actions ويعيد نصه
                $out = AdminTools::execute($name, $in, $conv, $admin);

                return self::finish('denied', $name, $out, $admin, $conv);
            }
        }

        // 3–4. التطبيع ثم التحقق
        $rules = $registry[$name]['rules'] ?? [];
        $in = InputNormalizer::normalize($in, $registry[$name]['properties'] ?? [], $rules);
        if ($rules !== []) {
            $v = Validator::make($in, array_map(fn ($r) => 'bail|'.$r, $rules));
            if ($v->fails()) {
                $fields = array_values(array_unique(array_map(fn ($k) => explode('.', $k)[0], array_keys($v->errors()->toArray()))));
                WaLog::warning('tool.validation', ['tool' => $name, 'conv_id' => $conv->id, 'reason' => implode(',', $fields)]);
                if (FeatureFlags::on('tool_validation_enforce')) {
                    return self::finish('failed', $name, ['ok' => false, 'error' => 'مدخلات غير صالحة', 'fields' => $fields,
                        'note' => 'اسأل العميل عن هذه المعلومات بوضوح ثم أعد المحاولة.'], $admin, $conv);
                }
                $in = self::clamp($in, $rules);
            }
        }

        // 5. التنفيذ
        try {
            $out = $admin ? AdminTools::execute($name, $in, $conv, $admin) : BotTools::execute($name, $in, $conv);
        } catch (\Throwable $e) {
            WaLog::error('bot.tool.failed', ['tool' => $name, 'conv_id' => $conv->id, 'exception' => $e]);

            return self::finish('failed', $name, ['ok' => false, 'error' => 'تعذّر تنفيذ العملية', 'note' => 'اعتذر بإيجاز واقترح تحويل المحادثة لموظف.'], $admin, $conv);
        }

        // 6. التصنيف: خطأ صريح أو ok=false فشل، وما سواه نجاح
        $status = isset($out['error']) || ($out['ok'] ?? null) === false ? 'failed' : 'ok';

        return self::finish($status, $name, $out, $admin, $conv);
    }

    private static function finish(string $status, string $name, array $out, ?WaAdminNumber $admin, WaConversation $conv): array
    {
        $max = $admin ? AdminTools::maxResultBytes($name) : BotTools::maxResultBytes($name);
        $shaped = ToolResult::shape($out, $max);
        if ($status === 'denied') {
            WaLog::warning('tool.denied', ['tool' => $name, 'conv_id' => $conv->id]);
        }

        return ['status' => $status, 'output' => $shaped, 'bytes' => ToolResult::bytes($shaped)];
    }

    /** وضع التسجيل: القيم إلى حدود قواعدها (نص أطول يُقصّ، وعدد خارج المدى يُقصر، وقائمة أطول تُقطع) */
    private static function clamp(array $in, array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (str_contains($field, '*') || !array_key_exists($field, $in) || $in[$field] === null) {
                continue;
            }
            $max = preg_match('/(?:^|\|)max:(\d+)/', $rule, $m) ? (int) $m[1] : null;
            $min = preg_match('/(?:^|\|)min:(-?\d+)/', $rule, $m) ? (int) $m[1] : null;
            $value = $in[$field];
            if (is_string($value) && $max !== null) {
                $in[$field] = mb_substr($value, 0, $max);
            } elseif (is_int($value) || is_float($value)) {
                $in[$field] = (int) max($min ?? PHP_INT_MIN, min($max ?? PHP_INT_MAX, $value));
            } elseif (is_array($value) && $max !== null) {
                $in[$field] = array_slice($value, 0, $max);
            } elseif (str_contains($rule, 'integer') && !is_numeric($value)) {
                $in[$field] = null;
            }
        }

        return $in;
    }
}
