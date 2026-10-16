<?php

namespace App\Models;

use App\Services\WhatsApp\AutoRuleMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * قاعدة رد حتمية (E37): قيم مطابقة حرفية (لا تعابير نمطية من المستخدم) ونص ثابت بالعربية والإنجليزية.
 * patterns_norm تُحسب عند كل حفظ بالتطبيع نفسه الذي تُقيَّم به الرسائل. وأي تعديل يغيّر ما تطابقه القاعدة
 * أو ما ترد به يلغي المعاينة (validated_at)، فلا تُفعَّل قبل أن تُجرَّب من جديد.
 */
class WaAutoRule extends Model
{
    public const MAX_RULES = 32;

    public const MAX_PATTERNS = 10;

    public const MAX_PATTERN_LEN = 60;

    public const MAX_REPLY = 1024;

    public const MAX_CONDITION_KEYS = 5;

    public const MATCH_TYPES = ['exact', 'contains', 'any_of'];

    /** location بعد E33 (sendLocation) وحدها */
    public const REPLY_KINDS = ['text'];

    /** ما يغيّر ما تطابقه القاعدة أو ما ترد به: تعديله يلغي المعاينة */
    public const CONTENT = ['match_type', 'patterns', 'conditions', 'max_words', 'reply_text_ar', 'reply_text_en', 'reply_kind', 'tag_topic'];

    protected $table = 'wa_auto_rules';

    protected $fillable = [
        'name', 'is_active', 'priority', 'match_type', 'patterns', 'conditions', 'max_words',
        'reply_text_ar', 'reply_text_en', 'reply_kind', 'tag_topic', 'cooldown_minutes', 'command_name',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'patterns' => 'array',
        'patterns_norm' => 'array',
        'conditions' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
        'max_words' => 'integer',
        'cooldown_minutes' => 'integer',
        'hits' => 'integer',
        'shadow_hits' => 'integer',
        'last_hit_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rule) {
            if (!$rule->exists || $rule->isDirty('patterns') || $rule->patterns_norm === null) {
                $rule->patterns_norm = AutoRuleMatcher::normalizeAll($rule->patterns);
            }
            if ($rule->exists && $rule->isDirty(self::CONTENT)) {
                $rule->validated_at = null;
                $rule->last_error = null;
            }
        });
    }

    /** القواعد المفعّلة بترتيب التقييم */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('priority')->orderBy('id');
    }

    /** كل القواعد بترتيب التقييم (للصفحة والمعاينة) */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('priority')->orderBy('id');
    }
}
