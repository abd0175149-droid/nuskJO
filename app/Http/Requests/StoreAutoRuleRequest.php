<?php

namespace App\Http\Requests;

use App\Models\WaAutoRule;
use App\Services\WhatsApp\AutoRuleMatcher;
use App\Services\WhatsApp\RuleTextGuard;
use App\Services\WhatsApp\TopicRouter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * قاعدة رد حتمية (E37): الحقول وحدودها من ثوابت WaAutoRule، والشروط من قائمة مسموحة، والنصان عبر RuleTextGuard
 * (لا سعر ولا دفع ولا هاتف مكتوب ولا رابط داخلي)، وكل قيمة مطابقة لا تفرغ بعد التطبيع.
 */
class StoreAutoRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('whatsapp.auto_rules');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'match_type' => ['required', Rule::in(WaAutoRule::MATCH_TYPES)],
            'patterns' => ['required', 'array', 'min:1', 'max:'.WaAutoRule::MAX_PATTERNS],
            'patterns.*' => ['required', 'string', 'min:1', 'max:'.WaAutoRule::MAX_PATTERN_LEN],
            'conditions' => ['nullable', 'array:'.implode(',', AutoRuleMatcher::CONDITIONS), 'max:'.WaAutoRule::MAX_CONDITION_KEYS],
            'conditions.msg_types' => ['sometimes', 'array', 'min:1'],
            'conditions.msg_types.*' => [Rule::in(AutoRuleMatcher::MSG_TYPES)],
            'conditions.linked' => ['sometimes', Rule::in(AutoRuleMatcher::LINKED)],
            'conditions.topics_any' => ['sometimes', 'array', 'max:10'],
            'conditions.topics_any.*' => [Rule::in(array_keys(TopicRouter::TOPICS))],
            'conditions.has_referral' => ['sometimes', 'boolean'],
            'conditions.referral_source_ids' => ['sometimes', 'array', 'max:20'],
            'conditions.referral_source_ids.*' => ['string', 'regex:/^[A-Za-z0-9_.:-]{1,64}$/'],
            'conditions.exclude_staff' => ['sometimes', 'boolean'],
            'max_words' => ['required', 'integer', 'min:1', 'max:20'],
            'cooldown_minutes' => ['required', 'integer', 'min:5', 'max:10080'],
            'reply_text_ar' => ['required', 'string', 'max:'.WaAutoRule::MAX_REPLY],
            'reply_text_en' => ['nullable', 'string', 'max:'.WaAutoRule::MAX_REPLY],
            // location بعد E33 (sendLocation)
            'reply_kind' => ['sometimes', Rule::in(WaAutoRule::REPLY_KINDS)],
            'tag_topic' => ['nullable', Rule::in(array_keys(TopicRouter::TOPICS))],
            'command_name' => ['nullable', 'string', 'max:32', 'not_regex:/^\s*\//'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم القاعدة مطلوب.',
            'patterns.required' => 'أضف قيمة مطابقة واحدة على الأقل.',
            'patterns.max' => 'عشر قيم على الأكثر لكل قاعدة.',
            'patterns.*.max' => 'القيمة حتى '.WaAutoRule::MAX_PATTERN_LEN.' حرفاً.',
            'conditions.array' => 'شرط غير معروف.',
            'conditions.max' => 'خمسة شروط على الأكثر.',
            'reply_text_ar.required' => 'النص العربي مطلوب.',
            'reply_kind.in' => 'الرد بالموقع غير متاح بعد: نص فقط.',
            'command_name.not_regex' => 'اسم الأمر بلا «/» في أوله.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            foreach ((array) $this->input('patterns', []) as $i => $p) {
                if (is_string($p) && AutoRuleMatcher::normalize($p) === '') {
                    $v->errors()->add("patterns.{$i}", 'القيمة فارغة بعد حذف الترقيم: اكتب كلمة.');
                }
            }
            foreach (['reply_text_ar', 'reply_text_en'] as $field) {
                $text = $this->input($field);
                if (is_string($text) && trim($text) !== '') {
                    foreach (RuleTextGuard::errors($text) as $error) {
                        $v->errors()->add($field, $error);
                    }
                }
            }
        }];
    }

    /** القيم للحفظ: بلا مسافات طرفية، والشروط الفارغة null */
    public function clean(): array
    {
        $trim = fn ($s) => $s === null ? null : preg_replace('/^\s+|\s+$/u', '', (string) $s);
        $conditions = array_filter((array) $this->input('conditions', []), fn ($v) => $v !== null && $v !== [] && $v !== '');

        return [
            'name' => $trim($this->input('name')),
            'match_type' => (string) $this->input('match_type'),
            'patterns' => array_values(array_unique(array_map($trim, (array) $this->input('patterns')))),
            'conditions' => $conditions ?: null,
            'max_words' => (int) $this->input('max_words'),
            'cooldown_minutes' => (int) $this->input('cooldown_minutes'),
            'reply_text_ar' => $trim($this->input('reply_text_ar')),
            'reply_text_en' => $trim($this->input('reply_text_en')) ?: null,
            'reply_kind' => 'text',
            'tag_topic' => $this->input('tag_topic') ?: null,
            'command_name' => $trim($this->input('command_name')) ?: null,
        ];
    }
}
