<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WaBotSetting;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * بطاقات أعلى الصندوق (E17.6): أعداد فقط (COUNT في SQL) محصورة بنطاق كل موظف (visibleTo، E04)، ومخزّنة
 * 60 ثانية لكل مستخدم. لا اسم ولا رقم ولا نص. وفلاتر القائمة المقابلة بالشروط نفسها.
 */
final class InboxSummary
{
    public const TTL_SECONDS = 60;

    /** حالات الصادر التي لم تصل العميل: فشل، أو بلا تأكيد من ميتا، أو حجبتها بوابة المحتوى */
    public const UNDELIVERED = ['failed', 'unconfirmed', 'blocked'];

    public static function for(User $u): array
    {
        return Cache::remember('wa:inbox-summary:'.$u->id, self::TTL_SECONDS, fn () => self::compute($u));
    }

    private static function compute(User $u): array
    {
        $visible = fn (): Builder => WaConversation::query()->visibleTo($u)->where('wa_conversations.status', '!=', 'merged');

        $failed = WaMessage::query()
            ->whereIn('conversation_id', $visible()->select('wa_conversations.id'))
            ->where('direction', 'out')
            ->whereIn('status', self::UNDELIVERED)
            ->where('created_at', '>=', self::todayStart())
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $out = [
            'waiting_staff' => $visible()->where('needs_attention', true)->count(),
            'window_closing' => self::windowClosing($visible())->count(),
            'failed_today' => (int) $failed->sum(),
            'failed_breakdown' => array_map('intval', $failed->all()),
            'channel' => self::channel(),
        ];

        if ($u->can('whatsapp.settings')) {
            $ids = StuckReplies::cachedIds();
            $out['stuck'] = $ids ? $visible()->whereIn('wa_conversations.id', $ids)->count() : 0;
            $out['failed_jobs'] = StuckReplies::failedJobs24h();
        }

        return $out;
    }

    /** عميل ينتظر (تدخّل أو وارد بلا رد) ونافذته تُغلق خلال 3 ساعات: شرط whatsapp:window-reminders نفسه */
    public static function windowClosing(Builder $q): Builder
    {
        return $q->where('last_inbound_at', '>', now()->subDay())
            ->where('last_inbound_at', '<=', now()->subHours(21))
            ->where(fn ($w) => $w->where('needs_attention', true)->orWhereExists(fn (QueryBuilder $m) => $m->selectRaw('1')->from('wa_messages')
                ->whereColumn('wa_messages.conversation_id', 'wa_conversations.id')
                ->where('wa_messages.direction', 'in')
                ->where('wa_messages.msg_type', '!=', 'reaction')
                ->whereRaw('wa_messages.id > COALESCE(wa_conversations.bot_answered_in_id, 0)')));
    }

    public static function failedToday(Builder $q): Builder
    {
        return $q->whereExists(fn (QueryBuilder $m) => $m->selectRaw('1')->from('wa_messages')
            ->whereColumn('wa_messages.conversation_id', 'wa_conversations.id')
            ->where('wa_messages.direction', 'out')
            ->whereIn('wa_messages.status', self::UNDELIVERED)
            ->where('wa_messages.created_at', '>=', self::todayStart()));
    }

    /** بداية اليوم بتوقيت عمّان (يوم المكتب)، بتوقيت التطبيق للمقارنة */
    public static function todayStart(): Carbon
    {
        return Carbon::now('Asia/Amman')->startOfDay()->setTimezone(config('app.timezone'));
    }

    /** حالة القناة بلا تفاصيل تقنية: ok | suspended | blocked | not_ready | bot_off */
    private static function channel(): array
    {
        $s = WaBotSetting::current();
        $state = match (true) {
            $s->sendingSuspended() => 'suspended',
            data_get($s->wa_channel_health, 'can_send_message') === 'BLOCKED' || data_get($s->wa_credentials_status, 'state') === 'critical' => 'blocked',
            !$s->channelReady() => 'not_ready',
            !$s->botReady() => 'bot_off',
            default => 'ok',
        };

        return ['state' => $state];
    }
}
