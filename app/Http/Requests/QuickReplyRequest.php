<?php

namespace App\Http\Requests;

use App\Models\WaQuickReply;
use App\Services\WhatsApp\QuickReplyText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** نص الرد الجاهز (E17.7): الطول والتصنيف، والمتغيرات المسموحة، ولا سعر ثابت، ولا طلب لجواز أو هوية */
class QuickReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('whatsapp.quick_replies');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:80'],
            'body' => ['required', 'string', 'min:2', 'max:1000'],
            'category' => ['required', 'string', Rule::in(array_keys(WaQuickReply::CATEGORIES))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'العنوان مطلوب.',
            'body.required' => 'نص الرد مطلوب.',
            'body.max' => 'النص أطول من 1000 حرف.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $body = (string) $this->input('body', '');
            if ($unknown = QuickReplyText::unknownVariables($body)) {
                $v->errors()->add('body', 'متغير غير مسموح: {{'.$unknown[0].'}} — المسموح: '.implode('، ', array_map(fn ($k) => '{{'.$k.'}}', array_keys(QuickReplyText::VARIABLES))));
            }
            if (QuickReplyText::hasPrice($body)) {
                $v->errors()->add('body', 'لا تكتب سعراً ثابتاً — استعمل {{quoted_price}} من طلب التسعير');
            }
            if (QuickReplyText::asksForIdDocument($body)) {
                $v->errors()->add('body', 'لا تطلب صور الجوازات أو الهويات عبر واتساب (سياسة واتساب) — الطريق رابط الرفع الآمن');
            }
        }];
    }

    /** النص مُعدّاً للحفظ: بلا مسافات طرفية */
    public function clean(): array
    {
        return [
            'title' => preg_replace('/^\s+|\s+$/u', '', (string) $this->input('title')),
            'body' => preg_replace('/^\s+|\s+$/u', '', (string) $this->input('body')),
            'category' => (string) $this->input('category'),
        ];
    }
}
