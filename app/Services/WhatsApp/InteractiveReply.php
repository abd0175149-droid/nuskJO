<?php

namespace App\Services\WhatsApp;

use App\Models\Offer;
use App\Models\QuoteRequest;
use App\Models\WaAdminNumber;
use App\Models\WaBotSetting;
use App\Models\WaChoiceSet;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\Outbound\InteractiveTypes;
use App\Support\FeatureFlags;
use Illuminate\Support\Str;

/**
 * أدوات البوت التفاعلية (E36): show_offers_menu وpresent_choices. الأداة لا ترسل: تبني الخيارات من ChoiceBuilder وتحفظها
 * في ReplyRun::pendingInteractive، ونص رد النموذج النهائي يصير body الرسالة، فهي رسالة واحدة. منذ OpenWA (2026-11-06) لا
 * أزرار في واتساب ويب: الخيارات تصل نصاً مرقماً (OpenWa\PayloadMapper) ويحل ChoiceResolver الرد بالرقم. نموذج Flow
 * لطلب التسعير أُزيل (لا مقابل له)، وrequest_quote بديله الحواري.
 *
 * الإعلان مشروط: العلم، والمحادثات التجريبية إن ضُبطت، وليست محادثة إدارة.
 */
final class InteractiveReply
{
    public const TOOLS = ['show_offers_menu', 'present_choices'];

    /** النص حين يُقسم الرد، أو يخلو */
    public const SPLIT_BODY = 'اختر من الخيارات:';

    public const FLOW_CTA = 'تعبئة طلب التسعير';

    public const STAGED_NOTE = 'ستُرسل الخيارات مع ردك التالي كأزرار. اكتب جملة قصيرة تمهّد لها (حتى 3 أسطر)، ولا تكرر العناوين ولا الأسعار.';

    public const NONE_NOTE = 'لا خيارات متاحة؛ تابع نصاً';

    /** هل تُعلن الأداة لهذه المحادثة الآن؟ (العلم والتفعيل في BotTools::isEnabled قبل هذا) */
    public static function offerable(WaConversation $conv, WaBotSetting $s, string $tool): bool
    {
        if (!$conv->exists) {
            return false;
        }
        $pilot = (array) config('whatsapp.interactive_pilot_conversations', []);
        if ($pilot !== [] && !in_array((int) $conv->id, array_map('intval', $pilot), true)) {
            return false;
        }
        if ($conv->is_admin_chat || ($conv->hasPhone() && WaAdminNumber::forPhone($conv->phone))) {
            return false;
        }
        return in_array($tool, self::TOOLS, true);
    }

    /** طلب تسعير أو حجز للمحادثة خلال 30 دقيقة (E17): لا نموذج ثانٍ فوقه */
    public static function recentQuote(WaConversation $conv): bool
    {
        return QuoteRequest::where('conversation_id', $conv->id)
            ->where('created_at', '>=', now()->subMinutes(QuoteRequestGuard::WINDOW_MINUTES))->exists();
    }

    // ==================== التجهيز (من الأدوات) ====================

    public static function showOffersMenu(array $in, WaConversation $conv): array
    {
        $category = isset($in['category']) && is_string($in['category']) && $in['category'] !== '' ? $in['category'] : null;
        if ($category !== null && ($t = TopicRouter::fromOfferCategory($category))) {
            // كما في get_offers: تصفّح تصنيف بعينه دليل كافٍ على الموضوع
            TopicRouter::tag($conv, $t, 'bot');
        }

        return self::stage(ChoiceBuilder::offersMenu($conv, $category));
    }

    public static function presentChoices(array $in, WaConversation $conv): array
    {
        $kind = (string) ($in['kind'] ?? '');
        if ($kind === 'category') {
            return self::stage(ChoiceBuilder::categories($conv));
        }
        $offer = Offer::forBot()->with(['groups.options.stays', 'options.stays'])->find((int) ($in['offer_id'] ?? 0));
        if (!$offer) {
            return ['ok' => false, 'error' => 'العرض غير متاح', 'note' => 'أخبر العميل أنّ العرض غير متاح واعرض البدائل.'];
        }
        $ref = isset($in['option_ref']) && is_string($in['option_ref']) ? trim($in['option_ref']) : null;

        return self::stage(match ($kind) {
            'offer_option' => ChoiceBuilder::offerOptions($conv, $offer),
            'room_type' => $ref ? ChoiceBuilder::roomTypes($conv, $offer, $ref) : null,
            'booking_actions' => ChoiceBuilder::bookingActions($conv, $offer, $ref, null),
            default => null,
        });
    }

    /** يحفظ آخر تجهيز في التشغيل (لا إرسال هنا) */
    private static function stage(?array $built): array
    {
        $run = ReplyRun::current();
        if ($built === null || $run === null) {
            return ['staged' => false, 'note' => self::NONE_NOTE];
        }
        $run->pendingInteractive = $built;

        return ['staged' => true, 'shown' => ChoiceBuilder::titles($built), 'note' => self::STAGED_NOTE];
    }

    // ==================== الإرسال (من BotEngine) ====================

    /**
     * رد البوت النهائي مع الرسالة التفاعلية المجهّزة: النص (بعد OutboundPolicy في dispatch كأي نص بوت) يصير body، فرسالة
     * واحدة بمفتاح الرد نفسه. تُحفظ المجموعة قبل الإرسال (رقمها في payload._meta.choice_set_id) وتُربط بالرسالة بعد قبول
     * البوابة، وتُحذف إن رُفضت. تحويل نفّذه التشغيل يُسقط الخيارات.
     */
    public static function deliver(WaConversation $conv, ReplyRun $run, string $text): array
    {
        $p = $run->pendingInteractive;
        $run->pendingInteractive = null;
        if ($p === null || $run->escalated || !self::stillAllowed($p)) {
            return WhatsAppChannel::sendText($conv, $text, 'bot', sendKey: SendKey::reply($conv, $run->uptoId, 'text'));
        }
        $limit = match ($p['type']) {
            'list' => InteractiveTypes::LIST_BODY,
            default => InteractiveTypes::BUTTON_BODY,
        };
        $text = trim($text) !== '' ? $text : self::SPLIT_BODY;

        if (mb_strlen($text) <= $limit) {
            $res = self::sendStaged($conv, $run, $p, $text, 'text');
            if ($res['ok'] || !empty($res['unknown'])) {
                return $res;
            }
            // خيارات بلا صالح: النص وحده بالمفتاح نفسه (الصف الفاشل حرّر مفتاحه)
            if (($res['code'] ?? null) === 'BAD_INTERACTIVE') {
                WaLog::warning('wa.interactive.fallback', ['conv_id' => $conv->id, 'meta_code' => $res['meta_code'] ?? null, 'kind' => $p['kind'], 'code' => $res['code'] ?? null]);

                return WhatsAppChannel::sendText($conv, $text, 'bot', sendKey: SendKey::reply($conv, $run->uptoId, 'text'));
            }

            return $res;
        }

        // نص أطول من حد الرسالة التفاعلية: النص ثم الخيارات بنص ثابت (رسالتان، نادراً)
        $res = WhatsAppChannel::sendText($conv, $text, 'bot', sendKey: SendKey::reply($conv, $run->uptoId, 'text'));
        if ($res['ok']) {
            self::sendStaged($conv, $run, $p, self::SPLIT_BODY, 'choices');
        }

        return $res;
    }

    private static function sendStaged(WaConversation $conv, ReplyRun $run, array $p, string $body, string $keyKind): array
    {
        /** @var WaChoiceSet $set */
        $set = $p['set'];
        $set->save();
        $opts = ['meta' => ['choice_set_id' => $set->id], 'header' => $p['header'] ?? null];
        $res = match ($p['type']) {
            'list' => WhatsAppChannel::sendList($conv, $body, (string) ($p['button'] ?? 'الخيارات'), (array) $p['sections'], 'bot', null,
                $opts + ['send_key' => SendKey::reply($conv, $run->uptoId, $keyKind)]),
            default => WhatsAppChannel::sendButtons($conv, $body, (array) $p['buttons'], 'bot', null,
                $opts + ['send_key' => SendKey::reply($conv, $run->uptoId, $keyKind)]),
        };

        if (!empty($res['replayed'])) {
            // المفتاح أُرسل في محاولة سابقة: رسالة العميل تحمل مجموعتها هي، فلا تحل هذه محلها
            $set->delete();
        } elseif ($res['ok'] || !empty($res['unknown'])) {
            // غير مؤكد: قد تكون وصلت، فتبقى المجموعة مربوطة بصفها (ويثبت المعرّف من ويبهوك الحالة)
            $wamid = is_string($res['message_id'] ?? null) && $res['message_id'] !== '' ? $res['message_id'] : null;
            $rowId = $res['row_id'] ?? ($wamid ? WaMessage::where('conversation_id', $conv->id)->where('wamid', $wamid)->value('id') : null);
            $set->forceFill(['message_id' => $rowId, 'wamid' => $wamid])->save();
            WaLog::info('wa.interactive.sent', ['conv_id' => $conv->id, 'kind' => $set->kind, 'set_id' => $set->id, 'message_id' => $rowId]);
        } else {
            $set->delete();
        }

        return $res;
    }

    /** العلم ما زال مفعّلاً لحظة الإرسال (أُطفئ أثناء التوليد؟ النص وحده) */
    private static function stillAllowed(array $p): bool
    {
        if (($p['type'] ?? null) === 'flow') {
            return false;
        }

        return FeatureFlags::on('interactive_choices');
    }
}
