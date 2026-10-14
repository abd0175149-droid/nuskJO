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

    /** إلى أحدث وارد: المحادثة صارت في يد موظف أو خُدمت كلها */
    public static function advanceToNewest(WaConversation $conv): void
    {
        $newest = (int) $conv->messages()->where('direction', 'in')->max('id');
        self::advance($conv, $newest);
    }
}
