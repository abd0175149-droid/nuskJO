<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaConversationPin;
use App\Support\FeatureFlags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * متابعة الصندوق (E17): الإغلاق والتأجيل والتثبيت و«غير مقروء» الداخلي. كلها حالة في قاعدتنا وحدها: لا أرشفة
 * ولا تثبيت ولا «غير مقروء» في Cloud API، ولا نداء لميتا.
 *
 * القاعدة التي لا تُكسر: محادثة تحتاج تدخّلاً لا تُخفى أبداً، مغلقة كانت أو مؤجلة، فلا تُغلق نافذة عميل ينتظر
 * (لا قوالب لدينا). والوارد يعيد الفتح ويمسح التأجيل.
 */
final class InboxFollowup
{
    /** أقصى تأجيل: قبل إغلاق نافذة الـ24 ساعة بربع ساعة */
    public const SNOOZE_MARGIN_MINUTES = 15;

    public const SNOOZE_MIN_MINUTES = 10;

    private static ?bool $available = null;

    /** الواجهة والقائمة خلف العلم؛ المسارات وإعادة الفتح عند الوارد تعمل دائماً */
    public static function enabled(): bool
    {
        return FeatureFlags::on('inbox_followup') && self::available();
    }

    /** الأعمدة موجودة (بين النشر والهجرة 2026_10_14_000017 لا توجد، فلا يكتبها الويبهوك) */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasColumn('wa_conversations', 'snoozed_until');
    }

    /** ما يُمسح عند وارد جديد من العميل */
    public static function reopenColumns(): array
    {
        return self::available() ? ['closed_at' => null, 'closed_by' => null, 'snoozed_until' => null, 'snoozed_by' => null] : [];
    }

    /** آخر موعد تأجيل ممكن، أو null إن كانت النافذة مغلقة أو أقصر من الحد الأدنى */
    public static function snoozeLimit(WaConversation $c): ?Carbon
    {
        if (!$c->isWindowOpen()) {
            return null;
        }
        $limit = $c->last_inbound_at->copy()->addDay()->subMinutes(self::SNOOZE_MARGIN_MINUTES);

        return $limit->gte(now()->addMinutes(self::SNOOZE_MIN_MINUTES)) ? $limit : null;
    }

    /** @return list<int> */
    public static function pinnedIds(User $u): array
    {
        if (!self::available()) {
            return [];
        }

        return WaConversationPin::where('user_id', $u->id)->pluck('conversation_id')->map(fn ($id) => (int) $id)->all();
    }

    /** حقول المتابعة لعنصر القائمة ورأس الخيط */
    public static function state(WaConversation $c, bool $pinned): array
    {
        $snoozed = $c->snoozed_until && $c->snoozed_until->isFuture();

        return [
            'closed' => $c->status === 'closed',
            'snoozed_until' => $snoozed ? $c->snoozed_until->toIso8601String() : null,
            'pinned' => $pinned,
            'marked_unread' => $c->marked_unread_at !== null,
            'marked_unread_by' => $c->marked_unread_at ? $c->markedUnreadBy?->name : null,
        ];
    }

    /**
     * فلاتر القائمة. «الكل» يُخفي المغلقة والمؤجلة إلا ما يحتاج تدخّلاً، وللمغلقة والمؤجلة والمثبّتة تبويباتها.
     * يُستدعى بعد visibleTo: يضيّق ولا يوسّع.
     */
    public static function applyFilter(Builder $q, string $filter, User $me): Builder
    {
        return match ($filter) {
            'all' => $q->where(fn ($w) => $w->where('wa_conversations.status', '!=', 'closed')->orWhere('needs_attention', true))
                ->where(fn ($w) => $w->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now())->orWhere('needs_attention', true)),
            'closed' => $q->where('wa_conversations.status', 'closed'),
            'snoozed' => $q->where('snoozed_until', '>', now()),
            'pinned' => $q->whereIn('wa_conversations.id', self::pinnedIds($me)),
            // فلاتر البطاقات (E17.6) بشروط InboxSummary نفسها
            'window_closing' => InboxSummary::windowClosing($q),
            'failed_today' => InboxSummary::failedToday($q),
            'stuck' => $me->can('whatsapp.settings') ? $q->whereIn('wa_conversations.id', StuckReplies::cachedIds()) : $q->whereRaw('1 = 0'),
            default => $q,
        };
    }
}
