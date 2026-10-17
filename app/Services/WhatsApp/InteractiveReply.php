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
 * أدوات البوت التفاعلية (E36): show_offers_menu وpresent_choices وsend_quote_form. الأداة لا ترسل: تبني الخيارات من
 * ChoiceBuilder وتحفظها في ReplyRun::pendingInteractive، ونص رد النموذج النهائي يصير body الرسالة التفاعلية، فهي رسالة
 * خدمة واحدة لا اثنتان (كل رسالة مدفوعة بعد الشريحة المجانية منذ 2026-10-01). النص أطول من حد ميتا وحده يُقسم:
 * النص ثم الخيارات بنص ثابت قصير.
 *
 * الإعلان مشروط: العلم، والمحادثات التجريبية إن ضُبطت، و10 دقائق على الأقل من النافذة (30 للنموذج)، وليست محادثة إدارة.
 */
final class InteractiveReply
{
    public const TOOLS = ['show_offers_menu', 'present_choices', 'send_quote_form'];

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
        $min = $tool === 'send_quote_form' ? (int) config('whatsapp.interactive.flow_min_window_minutes', 30) : (int) config('whatsapp.interactive.min_window_minutes', 10);
        if (($conv->windowMinutesLeft() ?? -1) < $min) {
            return false;
        }
        if ($tool === 'send_quote_form') {
            return self::flowId($s) !== null && !self::recentQuote($conv);
        }

        return true;
    }

    /** معرّف الـ Flow المنشور، أو null */
    public static function flowId(WaBotSetting $s): ?string
    {
        $id = (string) ($s->wa_quote_flow_id ?? '');

        return preg_match('/^\d{1,32}$/', $id) ? $id : null;
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

    /** نموذج طلب التسعير: flow_token غير قابل للتخمين (32 حرفاً) مربوط بالمحادثة، وينتهي بانتهاء النافذة */
    public static function sendQuoteForm(array $in, WaConversation $conv): array
    {
        $s = WaBotSetting::current();
        $flowId = self::flowId($s);
        $run = ReplyRun::current();
        if ($flowId === null || $run === null || self::recentQuote($conv)) {
            return ['staged' => false, 'note' => 'النموذج غير متاح الآن؛ اجمع التفاصيل بالحوار ثم استدعِ request_quote.'];
        }
        $run->pendingInteractive = [
            'type' => 'flow', 'kind' => 'quote_flow', 'flow_id' => $flowId, 'cta' => self::FLOW_CTA,
            'mode' => in_array($s->wa_quote_flow_mode, InteractiveTypes::FLOW_MODES, true) ? (string) $s->wa_quote_flow_mode : 'published',
            'set' => ChoiceBuilder::newSet($conv, 'quote_flow', [], Str::random(32)),
        ];

        return [
            'staged' => true,
            'note' => 'سيصل العميلَ مع ردك التالي زر «'.self::FLOW_CTA.'» يفتح نموذجاً قصيراً. اكتب جملة قصيرة تدعوه لتعبئته، '
                .'وأخبره أنه يستطيع الكتابة بدلاً منه إن شاء. لا تطرح أسئلة التسعير الآن ولا تذكر أي سعر.',
        ];
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
     * ميتا، وتُحذف إن رُفضت. رفض التنسيق (131009) يُرسل النص وحده. تحويل نفّذه التشغيل يُسقط الخيارات.
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
            'flow' => InteractiveTypes::FLOW_BODY,
            default => InteractiveTypes::BUTTON_BODY,
        };
        $text = trim($text) !== '' ? $text : self::SPLIT_BODY;

        if (mb_strlen($text) <= $limit) {
            $res = self::sendStaged($conv, $run, $p, $text, 'text');
            if ($res['ok'] || !empty($res['unknown'])) {
                return $res;
            }
            // رفض ميتا للتنسيق: النص وحده بالمفتاح نفسه (الصف الفاشل حرّر مفتاحه)
            if (($res['meta_code'] ?? null) === 131009 || ($res['code'] ?? null) === 'BAD_INTERACTIVE') {
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
            'flow' => WhatsAppChannel::sendFlow($conv, $body, (string) $p['flow_id'], (string) $set->nonce, (string) $p['cta'], 'bot',
                ['meta' => $opts['meta'], 'mode' => $p['mode'] ?? 'published', 'send_key' => SendKey::reply($conv, $run->uptoId, $keyKind)]),
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
            return FeatureFlags::on('quote_flow') && self::flowId(WaBotSetting::current()) !== null;
        }

        return FeatureFlags::on('interactive_choices');
    }
}
