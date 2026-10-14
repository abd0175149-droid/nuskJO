<?php

namespace App\Http\Requests;

use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;

/**
 * حفظ الإعدادات العامة بقائمة سماح: كل مفتاح خارج EDITABLE يُسقط ويُسجَّل ولا يُكتب أبداً.
 * لا يُرفض الطلب كله لأن صفحة الإعدادات ترسل كل ما تعرضه.
 * مفاتيح الملفات (الشعار والقوالب والترويسة) تُكتب من مسارات الرفع وحدها، والمفاتيح الداخلية
 * (reconcile_signature وrestore_review_pending) لا يكتبها إلا النظام.
 */
class UpdateSettingsRequest extends FormRequest
{
    public const EDITABLE = [
        'company_name_ar' => 'nullable|string|max:150',
        'company_name_en' => 'nullable|string|max:150',
        'company_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ ()-]*$/'],
        'company_email' => 'nullable|email|max:150',
        'company_address' => 'nullable|string|max:300',
        'company_hours' => 'nullable|string|max:300',
        'company_facebook' => 'nullable|url|max:255',
        'tax_number' => 'nullable|string|max:50',
        'invoice_prefix' => 'sometimes|required|alpha_dash|max:10',
        'receipt_prefix' => 'sometimes|required|alpha_dash|max:10',
        'transfer_prefix' => 'sometimes|required|alpha_dash|max:10',
        'expense_prefix' => 'sometimes|required|alpha_dash|max:10',
        'card_letterhead_top' => 'nullable|numeric|between:0,60',
        'card_letterhead_bottom' => 'nullable|numeric|between:0,60',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.update');
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        $dropped = [];

        foreach ((array) $this->input('settings', []) as $row) {
            $key = is_array($row) ? ($row['key'] ?? null) : null;
            if (is_string($key) && array_key_exists($key, self::EDITABLE)) {
                $values[$key] = $row['value'] ?? null;
            } elseif (is_string($key) && $key !== '') {
                $dropped[] = mb_substr($key, 0, 60);
            }
        }

        if ($dropped) {
            AuditLog::log(\App\Enums\AuditAction::SettingsDroppedKeys, 'Setting', null, null, ['keys' => array_slice(array_values(array_unique($dropped)), 0, 50)]);
        }

        $this->merge(['values' => $values]);
    }

    public function rules(): array
    {
        $rules = ['values' => 'array'];
        foreach (self::EDITABLE as $key => $rule) {
            $rules["values.{$key}"] = $rule;
        }

        return $rules;
    }
}
