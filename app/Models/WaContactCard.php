<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * بطاقة اتصال عامة معتمدة (E33): المكتب والمندوب والمرشد والمحاسبة، بموافقة مكتوبة من صاحب الرقم (consent_note وconsented_at
 * إلزاميان). مصدر sendContacts الوحيد، وOutboundPolicy ترفض أي رقم في الحمولة لا يطابق بطاقة فعّالة.
 * الرقم يُعرض في الجداول والتدقيق مقنّعاً (maskedPhone).
 */
class WaContactCard extends Model
{
    /** حد البطاقات في رسالة واحدة (حد ميتا 257، ونقيّده عمداً) */
    public const MAX_PER_MESSAGE = 3;

    /** افتراضات الجدول نفسها، فيعرفها الصف قبل إعادة قراءته */
    protected $attributes = ['bot_allowed' => false, 'is_active' => true, 'sort_order' => 0];

    protected $fillable = ['label', 'formatted_name', 'org', 'job_title', 'phone_e164', 'wa_id', 'email', 'url',
        'consent_user_id', 'consent_note', 'consented_at', 'bot_allowed', 'is_active', 'sort_order', 'created_by', 'updated_by'];

    protected $casts = ['consented_at' => 'datetime', 'bot_allowed' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeBotAllowed(Builder $query): Builder
    {
        return $query->where('bot_allowed', true);
    }

    /** كائن contacts[] الرسمي: الاسم، والجهة إن وُجدت، والرقم (وwa_id لرقم على واتساب)، والبريد والرابط إن وُجدا */
    public function toPayload(): array
    {
        $name = mb_substr((string) $this->formatted_name, 0, 80);
        $org = array_filter(['company' => $this->org ?: null, 'title' => $this->job_title ?: null]);

        return array_filter([
            'name' => ['formatted_name' => $name, 'first_name' => $name],
            'org' => $org ?: null,
            'phones' => [array_filter(['phone' => (string) $this->phone_e164, 'type' => 'WORK', 'wa_id' => $this->wa_id ?: null])],
            'emails' => $this->email ? [['email' => (string) $this->email, 'type' => 'WORK']] : null,
            'urls' => $this->url ? [['url' => (string) $this->url, 'type' => 'WORK']] : null,
        ], fn ($v) => $v !== null);
    }

    /** للعرض والتدقيق: رمز الدولة وآخر 4 أرقام */
    public function maskedPhone(): string
    {
        return self::mask((string) $this->phone_e164);
    }

    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 7) {
            return '•••';
        }

        return '+'.substr($digits, 0, 3).'…'.substr($digits, -4);
    }
}
