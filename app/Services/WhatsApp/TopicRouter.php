<?php

namespace App\Services\WhatsApp;

use App\Models\Offer;
use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Support\Facades\DB;

/**
 * توجيه المحادثات بالموضوع.
 *
 * المحادثة تحمل وسماً أو أكثر. لكل موضوع موظفون مختصّون، وأحدهم «أساسي»
 * يصله إشعار التحويل. الموظف يرى كل محادثة تحمل وسماً من اختصاصه، فمحادثة
 * بوسمين يراها الموظفان — وهذا مقصود: عميل يسأل عن عمرة وتأشيرة معاً
 * يخصّ الاثنين.
 */
class TopicRouter
{
    /** المواضيع = تصنيفات العروض + موضوعان لا يغطّيهما البيع */
    public const TOPICS = [
        'umrah' => 'عمرة',
        'hajj' => 'حج',
        'package' => 'باقة',
        'tour' => 'رحلات سياحية',
        'visa' => 'تأشيرات',
        'flight' => 'تذاكر طيران',
        'hotel' => 'فنادق',
        'transport' => 'نقل',
        'complaint' => 'شكوى أو مشكلة',
        'other' => 'أخرى / غير محدّد',
    ];

    public static function label(string $topic): string
    {
        return self::TOPICS[$topic] ?? $topic;
    }

    public static function valid(string $topic): bool
    {
        return array_key_exists($topic, self::TOPICS);
    }

    /**
     * يضيف وسماً للمحادثة ويوكلها لمختصّه إن لم تكن موكلة بعد.
     *
     * @return bool هل أُضيف وسم جديد فعلاً؟
     */
    public static function tag(WaConversation $conv, string $topic, string $source = 'bot', ?int $by = null): bool
    {
        if (!self::valid($topic)) {
            return false;
        }

        $exists = DB::table('wa_conversation_topics')
            ->where('conversation_id', $conv->id)->where('topic', $topic)->exists();

        if ($exists) {
            return false;
        }

        DB::table('wa_conversation_topics')->insert([
            'conversation_id' => $conv->id,
            'topic' => $topic,
            'source' => $source,
            'created_by' => $by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // التوكيل الصامت: يرى المختصّ المحادثة ويتابع ردود البوت قبل أي تحويل
        if (!$conv->assigned_to && ($owner = self::primaryFor($topic))) {
            $conv->forceFill(['assigned_to' => $owner])->saveQuietly();
        }

        return true;
    }

    /** إزالة وسم */
    public static function untag(WaConversation $conv, string $topic): void
    {
        DB::table('wa_conversation_topics')
            ->where('conversation_id', $conv->id)->where('topic', $topic)->delete();
    }

    /** وسوم المحادثة بترتيب إضافتها */
    public static function topicsOf(WaConversation $conv): array
    {
        return DB::table('wa_conversation_topics')
            ->where('conversation_id', $conv->id)
            ->orderBy('id')
            ->get(['topic', 'source'])
            ->map(fn ($r) => ['key' => $r->topic, 'label' => self::label($r->topic), 'source' => $r->source])
            ->all();
    }

    /** الموظف الأساسي لموضوع، أو أي مختصّ به إن لم يُحدَّد أساسي */
    public static function primaryFor(string $topic): ?int
    {
        $rows = DB::table('wa_topic_users')->where('topic', $topic)->get(['user_id', 'is_primary']);

        if ($rows->isEmpty()) {
            return null;
        }

        return (int) ($rows->firstWhere('is_primary', true)->user_id ?? $rows->first()->user_id);
    }

    /** مواضيع اختصاص موظف */
    public static function topicsForUser(int $userId): array
    {
        return DB::table('wa_topic_users')->where('user_id', $userId)->pluck('topic')->all();
    }

    /**
     * من يُوكَل عند التحويل.
     * الأولوية: توكيل قائم ← مختصّ الموضوع ← موظف العميل المسؤول.
     * (المالك اختار تقديم الموضوع على علاقة العميل بموظفه.)
     */
    public static function assigneeFor(WaConversation $conv): ?int
    {
        if ($conv->assigned_to) {
            return (int) $conv->assigned_to;
        }

        foreach (self::topicsOf($conv) as $t) {
            if ($owner = self::primaryFor($t['key'])) {
                return $owner;
            }
        }

        if ($conv->client_id && ($emp = $conv->client?->employee_id)) {
            return \App\Models\Employee::find($emp)?->user_id;
        }

        return null;
    }

    /**
     * حصر الاستعلام بما يراه المستخدم.
     * من يملك whatsapp.view_all يرى الكل؛ وغيره يرى ما يحمل وسماً من اختصاصه
     * أو ما وُكّل له صراحةً.
     */
    public static function scopeVisible($query, User $user)
    {
        if ($user->can('whatsapp.view_all')) {
            return $query;
        }

        $topics = self::topicsForUser($user->id);

        return $query->where(function ($q) use ($topics, $user) {
            $q->where('assigned_to', $user->id);

            if ($topics) {
                $q->orWhereExists(function ($sub) use ($topics) {
                    $sub->selectRaw(1)
                        ->from('wa_conversation_topics')
                        ->whereColumn('wa_conversation_topics.conversation_id', 'wa_conversations.id')
                        ->whereIn('wa_conversation_topics.topic', $topics);
                });
            }
        });
    }

    /** خريطة الموضوع ← الموظفون، لواجهة الإدارة */
    public static function routingTable(): array
    {
        $rows = DB::table('wa_topic_users as tu')
            ->join('users as u', 'u.id', '=', 'tu.user_id')
            ->get(['tu.topic', 'tu.user_id', 'tu.is_primary', 'u.name']);

        $out = [];
        foreach (self::TOPICS as $key => $label) {
            $users = $rows->where('topic', $key)->map(fn ($r) => [
                'user_id' => (int) $r->user_id,
                'name' => $r->name,
                'is_primary' => (bool) $r->is_primary,
            ])->values()->all();

            $out[] = ['topic' => $key, 'label' => $label, 'users' => $users];
        }

        return $out;
    }

    /** الموضوع المقابل لتصنيف عرض — التصنيفات والمواضيع متطابقة بالتصميم */
    public static function fromOfferCategory(?string $category): ?string
    {
        return $category && array_key_exists($category, Offer::CATEGORIES) && self::valid($category)
            ? $category
            : null;
    }
}
