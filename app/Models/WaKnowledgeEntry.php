<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * معلومة في قاعدة معرفة بوت العملاء.
 * المعلومات الفعّالة تُجمع بترتيبها وتُرسل مع كل ردّ — فطولها كلفة.
 */
class WaKnowledgeEntry extends Model
{
    protected $table = 'wa_knowledge_entries';

    /** حدود تحمي الكلفة ووضوح السياق */
    public const MAX_TITLE = 150;
    public const MAX_CONTENT = 2000;
    public const MAX_TOTAL = 20000;

    protected $fillable = [
        'title', 'content', 'is_active', 'sort_order', 'source',
        'updated_by_user_id', 'updated_by_admin_id',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** النصّ الذي يقرؤه البوت: كل معلومة فعّالة بعنوانها */
    public static function composed(): string
    {
        return self::active()->get()
            ->map(fn ($e) => "=== {$e->title} ===\n" . trim($e->content))
            ->implode("\n\n");
    }

    /** مجموع أحرف المعلومات الفعّالة — اختياريّاً باستثناء معلومة تُعدَّل */
    public static function activeChars(?int $exceptId = null): int
    {
        return (int) self::where('is_active', true)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['title', 'content'])
            ->sum(fn ($e) => mb_strlen($e->title) + mb_strlen($e->content));
    }
}
