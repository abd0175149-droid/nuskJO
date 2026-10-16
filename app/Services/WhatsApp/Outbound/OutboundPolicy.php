<?php

namespace App\Services\WhatsApp\Outbound;

use App\Models\WaConversation;
use App\Services\WhatsApp\Outbound\Rules\InternalFieldsRule;
use App\Services\WhatsApp\Outbound\Rules\OptOutRule;
use App\Services\WhatsApp\Outbound\Rules\SensitiveIdRule;
use App\Services\WhatsApp\Outbound\Rules\ToolLeakRule;
use App\Services\WhatsApp\WaLog;
use App\Support\FeatureFlags;

/**
 * بوابة المحتوى الصادر (E15.9): تُفحص كل حقول النص في كل رسالة داخل WhatsAppChannel::dispatch قبل نداء ميتا.
 *  - رسائل البوت والنظام: تسرّب الأدوات، والحقول الداخلية وJSON الخام وآثار الأخطاء، والمعرّفات الحساسة. ما يُوجد
 *    يُسجَّل (outbound_policy_enforce مطفأ)، أو يحجب الرسالة ويحوّل المحادثة لموظف (مفعّل). خطأ داخل الفحص يحجب.
 *  - رسائل الموظف: المعرّفات الحساسة تحذير يؤكده الموظف (409 في الصندوق)، لا حجب. خطأ داخل الفحص يمر.
 *  - محادثات أرقام الإدارة داخلية: لا تُفحص (تقارير الربح وأسماء الأدوات من صلب عملها).
 */
final class OutboundPolicy
{
    public const CONTEXTS = ['reply', 'code', 'broadcast', 'reminder', 'campaign'];

    /** للموظف وللواجهة: وصف الأسباب بلا تفاصيل */
    public const LABELS = [
        'card' => 'رقم بطاقة',
        'iban' => 'رقم حساب بنكي (IBAN)',
        'passport' => 'رقم جواز',
        'national_id' => 'رقم وطني',
        'tool_leak' => 'أسماء أدوات داخلية',
        'internal_field' => 'حقل داخلي (تكلفة أو ربح)',
        'raw_json' => 'بيانات خام',
        'trace' => 'أثر خطأ تقني',
        'opted_out' => 'العميل أوقف الرسائل',
        'error' => 'تعذّر فحص الرسالة',
    ];

    public static function enforced(): bool
    {
        return FeatureFlags::on('outbound_policy_enforce');
    }

    public static function evaluate(WaConversation $conv, array $payload, string $displayBody, string $source, string $context = 'reply'): Decision
    {
        if ($conv->is_admin_chat) {
            return new Decision;
        }

        try {
            $texts = self::texts($payload, $displayBody);
            if ($source === 'staff') {
                return new Decision(warnings: (new SensitiveIdRule)->check($conv, $texts, $context));
            }

            $reasons = [];
            foreach ([new ToolLeakRule, new InternalFieldsRule, new SensitiveIdRule, new OptOutRule] as $rule) {
                array_push($reasons, ...$rule->check($conv, $texts, $context));
            }

            return new Decision(reasons: array_values(array_unique($reasons)));
        } catch (\Throwable $e) {
            WaLog::error('wa.policy.failed', ['conv_id' => $conv->id, 'source' => $source, 'exception' => $e]);

            // مغلق عند الفشل للآلي (حين يُفرض)، ومفتوح للموظف
            return $source === 'staff' ? new Decision : new Decision(reasons: ['error']);
        }
    }

    /** نص الأسباب للموظف: «رقم بطاقة، رقم جواز» */
    public static function describe(array $reasons): string
    {
        return implode('، ', array_map(fn ($r) => self::LABELS[$r] ?? $r, $reasons));
    }

    /**
     * كل حقول النص: النص، وتعليق الصورة أو المستند واسمه، ونص الرسالة التفاعلية وزر القائمة وعناوين الأزرار والصفوف ووصفها.
     *
     * @return list<string>
     */
    public static function texts(array $payload, string $displayBody): array
    {
        $out = [
            data_get($payload, 'text.body'),
            data_get($payload, 'image.caption'),
            data_get($payload, 'document.caption'),
            // اسم المستند يراه العميل (E32): «جواز-A1234567.pdf» يحمل الرقم كالنص
            data_get($payload, 'document.filename'),
            data_get($payload, 'video.caption'),
            data_get($payload, 'interactive.body.text'),
            data_get($payload, 'interactive.header.text'),
            data_get($payload, 'interactive.footer.text'),
            data_get($payload, 'interactive.action.button'),
        ];
        foreach ((array) data_get($payload, 'interactive.action.buttons', []) as $b) {
            $out[] = data_get($b, 'reply.title');
        }
        foreach ((array) data_get($payload, 'interactive.action.sections', []) as $section) {
            $out[] = data_get($section, 'title');
            foreach ((array) data_get($section, 'rows', []) as $row) {
                $out[] = data_get($row, 'title');
                $out[] = data_get($row, 'description');
            }
        }
        $out = array_values(array_filter($out, fn ($t) => is_string($t) && trim($t) !== ''));

        return $out ?: [$displayBody];
    }
}
