<?php

namespace App\Services\WhatsApp;

use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * علامة الرد: bot_answered_in_id = أعلى وارد خُدم (برد البوت، أو رد موظف، أو تحويل، أو «تمّت المعالجة»).
 * الوارد المعلّق = ما بعد العلامة. هكذا لا تضيع رسالة وصلت أثناء توليد رد سابق (كان رد A يُحفظ بعد B
 * فتظن وظيفة B أن آخر رسالة صادرة فتسكت).
 */
final class ReplyState
{
    /** سبب التدخّل (attention_reason) => نص الرقاقة في الصندوق والإشعار */
    public const REASONS = [
        'stale' => '⏱️ وصلت متأخرة',
        'reconcile_exhausted' => '🔁 تعذّر الرد الآلي',
        'bot_paused' => '👤 ينتظر موظفاً',
        'with_staff' => '👤 ينتظر موظفاً',
        'send_failed' => '❌ فشل الإرسال',
        'job_failed' => '⚠️ خلل في البوت',
        'budget' => '⏳ تجاوز زمن الرد',
        'bot_off' => '🔕 البوت مطفأ',
        'rate_limit' => '🚦 تجاوز الحد',
        'window_closed' => '🔒 النافذة مغلقة',
        'handoff' => '🙋 طلب موظفاً',
    ];

    private static ?bool $available = null;

    /** العمود موجود (بين النشر والهجرة 2026_10_14_000007 لا يوجد، فتعمل البوابة القديمة) */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasColumn('wa_conversations', 'bot_answered_in_id');
    }

    public static function mark(WaConversation $conv): int
    {
        return (int) WaConversation::whereKey($conv->id)->value('bot_answered_in_id');
    }

    public static function pendingQuery(WaConversation $conv): HasMany
    {
        return $conv->messages()
            ->where('direction', 'in')
            ->where('msg_type', '!=', 'reaction')
            ->where('id', '>', self::mark($conv));
    }

    public static function hasPending(WaConversation $conv): bool
    {
        return self::pendingQuery($conv)->exists();
    }

    public static function newestPending(WaConversation $conv): ?WaMessage
    {
        return self::pendingQuery($conv)->orderByDesc('id')->first();
    }

    /** رتيب: لا تتراجع العلامة أبداً */
    public static function advance(WaConversation $conv, int $upto): void
    {
        if ($upto <= 0 || !self::available()) {
            return;
        }

        WaConversation::whereKey($conv->id)
            ->where(fn ($q) => $q->whereNull('bot_answered_in_id')->orWhere('bot_answered_in_id', '<', $upto))
            ->update(['bot_answered_in_id' => $upto]);
        $conv->setRawAttributes(array_merge($conv->getAttributes(), ['bot_answered_in_id' => max(self::mark($conv), $upto)]), true);
    }

    /**
     * وارد لن يرد عليه البوت: شارة «تدخّل» بسبب ظاهر، والمحادثة صارت لموظف فتتحرك العلامة إلى أحدث وارد
     * (لا يجيب البوت لاحقاً عن رسائل كانت للموظف). يُشعر الموكَّل وإلا المدراء، مرة كل 30 دقيقة للمحادثة،
     * ولا إشعار إن ردّ موظف فيها خلال 10 دقائق (الحوار حيّ). النص عام: رقم المحادثة والسبب، بلا اسم ولا نص.
     */
    public static function markUnserved(WaConversation $conv, string $reason): void
    {
        $conv->forceFill(['needs_attention' => true] + (self::available() ? ['attention_reason' => $reason] : []))->save();
        self::advanceToNewest($conv);

        try {
            \App\Models\WaBotUsage::create([
                'conversation_id' => $conv->id, 'source' => 'gate', 'model' => (string) \App\Models\WaBotSetting::current()->model,
                'calls' => 0, 'prompt_tokens' => 0, 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0,
                'tools_used' => [$reason], 'leaked' => false, 'latency_ms' => 0, 'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // القياس ليس حرجاً
        }
        WaLog::warning('reply.unserved', ['conv_id' => $conv->id, 'reason' => $reason]);

        $staffActive = $conv->messages()->where('source', 'staff')->where('created_at', '>=', now()->subMinutes(10))->exists();
        if ($staffActive || !\Illuminate\Support\Facades\Cache::add("wa:unserved:{$conv->id}", 1, now()->addMinutes(30))) {
            return;
        }

        $title = '⚠️ محادثة تحتاج تدخّلاً';
        $body = "محادثة #{$conv->id} — " . (self::REASONS[$reason] ?? $reason);
        if ($conv->assigned_to) {
            try {
                \App\Services\NotificationService::send((int) $conv->assigned_to, $title, $body, ['type' => 'whatsapp', 'icon' => '💬', 'action_url' => '/whatsapp/inbox?conv=' . $conv->id]);
            } catch (\Throwable) {
                // الإشعار ليس حرجاً: الشارة في الصندوق باقية
            }
        } else {
            WhatsAppInbox::notifyAdmins($title, $body, '/whatsapp/inbox?conv=' . $conv->id);
        }

        // المتأخرة تُجمَّع للإدارة: إشعار واحد في الساعة مع إشعار الموكَّل
        if ($reason === 'stale' && $conv->assigned_to && \Illuminate\Support\Facades\Cache::add('wa:stale:alert:' . now()->format('YmdH'), 1, now()->addHour())) {
            WhatsAppInbox::notifyAdmins('⏱️ رسائل وصلت متأخرة', 'وصلت رسائل واتساب متأخرة فلم يرد عليها البوت — راجع فلتر «تدخّل».', '/whatsapp/inbox?filter=attention');
        }
    }

    /** إلى أحدث وارد: المحادثة صارت في يد موظف أو خُدمت كلها */
    public static function advanceToNewest(WaConversation $conv): void
    {
        $newest = (int) $conv->messages()->where('direction', 'in')->max('id');
        self::advance($conv, $newest);
    }
}
