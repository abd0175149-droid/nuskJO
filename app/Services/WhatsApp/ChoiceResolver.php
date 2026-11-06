<?php

namespace App\Services\WhatsApp;

use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\WaChoiceSet;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Support\Facades\Schema;

/**
 * التحقق من اختيار العميل لخيار أرسله البوت (E36)، قبل حفظ الوارد. واتساب ويب بلا أزرار (2026-11-06)، فالخيارات تصل
 * العميل نصاً مرقماً (OpenWa\PayloadMapper)، ويرد برقم الخيار (1، 2، ٣) أو بعنوانه حرفياً: يُحوَّل الرد إلى معرّف الخادم
 * ch:{nonce}:{key} من آخر رسالة خيارات صادرة في المحادثة، فيمر بالفحوص نفسها: المجموعة من المحادثة نفسها، وهي أحدث
 * مجموعة من نوعها، ولم تنتهِ، والعرض والخيار والغرفة ما زالت متاحة الآن. النتيجة في payload._choice، والنموذج يرى
 * وصفاً مؤكداً لا معرّفاً خاماً. رد اقتبس رسالة الخيارات يطابقها بالاقتباس، وغيره يطابق آخر رسالة خيارات وحدها.
 */
final class ChoiceResolver
{
    public const PATTERN = '/^ch:([A-Za-z0-9]{16}):(r\d{1,2}|book|ask|human)$/';

    /** أسباب الرفض بالعربية (للنموذج وللخيط) */
    public const REASONS = [
        'unknown' => 'قائمة غير معروفة',
        'foreign' => 'من محادثة أخرى',
        'bad_key' => 'خيار غير موجود',
        'no_context' => 'ليس من رسالة الخيارات نفسها',
        'superseded' => 'قائمة قديمة',
        'expired' => 'انتهت صلاحية الخيار',
        'offer_gone' => 'العرض لم يعد متاحاً',
        'option_gone' => 'الخيار لم يعد متاحاً',
        'room_gone' => 'سعة الغرفة لم تعد متاحة',
    ];

    private static ?bool $available = null;

    /** @return array{set_id: ?int, kind: ?string, key: string, valid: bool, reason: ?string, ref: array, label: ?string}|null */
    public static function resolve(WaConversation $conv, InboundMessage $in): ?array
    {
        if (!(self::$available ??= Schema::hasTable('wa_choice_sets'))) {
            return null;
        }
        $buttonId = $in->type === 'interactive' ? $in->buttonId : null;
        $replyTo = $in->replyToWamid;
        if ($buttonId === null && $in->type === 'text' && ($picked = self::numbered($conv, $in))) {
            [$buttonId, $replyTo] = $picked;
        }
        if ($buttonId === null || !preg_match(self::PATTERN, $buttonId, $m)) {
            return null;
        }
        [, $nonce, $key] = $m;

        $set = WaChoiceSet::where('nonce', $nonce)->first();
        if (!$set) {
            return self::result(null, $key, false, 'unknown');
        }
        // مجموعة محادثة أخرى: لا يُحفظ رقمها ولا عناوينها هنا
        if ((int) $set->conversation_id !== (int) $conv->id) {
            return self::result(null, $key, false, 'foreign');
        }
        $option = $set->kind !== 'quote_flow' ? $set->option($key) : null;
        $reason = match (true) {
            $option === null => 'bad_key',
            !self::contextMatches($conv, $set, $replyTo) => 'no_context',
            WaChoiceSet::where('conversation_id', $conv->id)->where('kind', $set->kind)->where('id', '>', $set->id)->exists() => 'superseded',
            $set->isExpired() => 'expired',
            default => self::stillAvailable((array) ($option['ref'] ?? [])),
        };
        if ($reason === null && $set->consumed_at === null) {
            $set->forceFill(['consumed_at' => now()])->save();
        }
        WaLog::info('wa.choice.resolved', ['conv_id' => $conv->id, 'set_id' => $set->id, 'kind' => $set->kind, 'reason' => $reason ?? 'valid']);

        return self::result($set, $key, $reason === null, $reason, $option);
    }

    /**
     * رد نصي برقم خيار أو بعنوانه على رسالة خيارات صادرة: [معرّف الخيار، wamid رسالة الخيارات]، أو null.
     * الرسالة المقتبسة إن اقتبس العميل رسالة خيارات، وإلا آخر رسالة خيارات صادرة خلال 24 ساعة، بشرط ألا يكون بعدها
     * رد صادر آخر من البوت أو الموظف (فالرقم عندها جواب سؤال آخر).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function numbered(WaConversation $conv, InboundMessage $in): ?array
    {
        $text = trim(strtr($in->body, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        $text = rtrim($text, '.)-– ');
        if ($text === '' || mb_strlen($text) > 80) {
            return null;
        }
        $q = WaMessage::where('conversation_id', $conv->id)->where('direction', 'out')->where('msg_type', 'interactive')->whereNotNull('wamid');
        $menu = $in->replyToWamid ? (clone $q)->where('wamid', $in->replyToWamid)->first(['id', 'wamid', 'payload']) : null;
        if ($menu === null) {
            $menu = $q->where('created_at', '>=', now()->subDay())->orderByDesc('id')->first(['id', 'wamid', 'payload']);
            if ($menu === null || WaMessage::where('conversation_id', $conv->id)->where('direction', 'out')->where('id', '>', $menu->id)
                ->whereIn('source', ['bot', 'staff', 'staff_phone'])->exists()) {
                return null;
            }
        }
        $options = OpenWa\PayloadMapper::options((array) data_get($menu->payload, 'interactive', []));
        if ($options === []) {
            return null;
        }
        $pick = null;
        if (preg_match('/^\d{1,2}$/', $text)) {
            $pick = $options[(int) $text - 1] ?? null;
        } else {
            $norm = TextNormalizer::forSearch($text);
            foreach ($options as $o) {
                if (TextNormalizer::forSearch($o['title']) === $norm) {
                    $pick = $o;
                    break;
                }
            }
        }

        return $pick !== null && $pick['id'] !== '' ? [$pick['id'], (string) $menu->wamid] : null;
    }

    /** صوت في استطلاع (poll.vote): لا يُرسل نُسك استطلاعات اليوم (الخيارات نص مرقم)، فلا شيء يُحسم هنا */
    public static function fromPollVote(\App\Models\WaChannel $channel, array $data): ?int
    {
        WaLog::info('wa.choice.poll_vote_ignored', ['channel_id' => $channel->id]);

        return null;
    }

    /** الوصف الذي يراه النموذج بدل «[اختيار:id]» */
    public static function describe(array $choice): string
    {
        if (!($choice['valid'] ?? false)) {
            return '[ضغط على خيار قديم لم يعد صالحاً ('.self::reasonLabel($choice['reason'] ?? null).') — أعد عرض الخيارات الحالية]';
        }
        $ref = (array) ($choice['ref'] ?? []);
        $parts = [];
        if (!empty($ref['category'])) {
            $parts[] = 'تصنيف '.(Offer::CATEGORIES[$ref['category']] ?? $ref['category']);
        }
        if (!empty($ref['offer_id'])) {
            $parts[] = 'عرض رقم '.(int) $ref['offer_id'];
        }
        if (!empty($ref['option_ref'])) {
            $parts[] = 'خيار '.$ref['option_ref'];
        }
        if (!empty($ref['room'])) {
            $parts[] = 'غرفة '.(OfferOption::ROOM_TYPES[$ref['room']] ?? $ref['room']);
        }
        $verb = ($choice['kind'] ?? '') === 'booking_actions' ? 'ضغط زر' : 'اختار من القائمة';

        return "[{$verb}: ".($choice['label'] ?? '—').($parts ? ' — مرجع مؤكد: '.implode('، ', $parts) : '').']';
    }

    public static function reasonLabel(?string $reason): string
    {
        return self::REASONS[$reason] ?? 'خيار غير صالح';
    }

    /** بين الوارد المنتظر نقرة على خيار أرسله البوت (صالحة أو قديمة) */
    public static function hasChoice(iterable $messages): bool
    {
        foreach ($messages as $m) {
            if (is_array(data_get($m->payload, '_choice'))) {
                return true;
            }
        }

        return false;
    }

    /** نقرة «كلّم موظفاً» من أزرارنا: تحويل بالكود بلا نموذج، صالحة أم قديمة */
    public static function wantsHuman(iterable $messages): bool
    {
        foreach ($messages as $m) {
            $c = data_get($m->payload, '_choice');
            if (is_array($c) && ($c['key'] ?? null) === ChoiceBuilder::HUMAN && !in_array($c['reason'] ?? null, ['unknown', 'foreign'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * اختيار مؤكد للحجز خلال 30 دقيقة: «احجز هذا» من أزرار الحجز، أو سعة غرفة من قائمتها. confirm_booking يقدّمه على
     * وسائط النموذج. @return array{offer_id: int, option_ref?: string, room?: string}|null
     */
    public static function confirmedBooking(WaConversation $conv): ?array
    {
        // الاختيار يصل نصاً مرقماً منذ OpenWA (والأزرار القديمة interactive)
        $rows = $conv->messages()->where('direction', 'in')->whereIn('msg_type', ['interactive', 'text'])
            ->where('created_at', '>=', now()->subMinutes(QuoteRequestGuard::WINDOW_MINUTES))
            ->orderByDesc('id')->limit(10)->get(['id', 'payload']);
        foreach ($rows as $m) {
            $c = data_get($m->payload, '_choice');
            if (!is_array($c) || !($c['valid'] ?? false)) {
                continue;
            }
            if ((($c['kind'] ?? null) === 'booking_actions' && ($c['key'] ?? null) === ChoiceBuilder::BOOK) || ($c['kind'] ?? null) === 'room_type') {
                $ref = (array) ($c['ref'] ?? []);

                return ((int) ($ref['offer_id'] ?? 0)) > 0 ? array_filter([
                    'offer_id' => (int) $ref['offer_id'],
                    'option_ref' => isset($ref['option_ref']) ? (string) $ref['option_ref'] : null,
                    'room' => isset($ref['room']) ? (string) $ref['room'] : null,
                ], fn ($v) => $v !== null) : null;
            }
        }

        return null;
    }

    /** وسائط confirm_booking بعد تقديم الاختيار المؤكد عليها؛ الخلاف يُسجَّل (wa.choice_override) */
    public static function applyToBooking(WaConversation $conv, array $in): array
    {
        $picked = self::confirmedBooking($conv);
        if ($picked === null) {
            return $in;
        }
        $room = isset($picked['room']) ? (OfferOption::ROOM_TYPES[$picked['room']] ?? null) : null;
        $override = array_filter([
            'offer_id' => $picked['offer_id'],
            'option_ref' => $picked['option_ref'] ?? null,
            'room_type' => $room,
        ], fn ($v) => $v !== null);
        foreach ($override as $k => $v) {
            if (($in[$k] ?? null) != $v) {
                WaLog::info('wa.choice_override', ['conv_id' => $conv->id, 'offer_id' => $picked['offer_id'], 'field' => $k]);
                break;
            }
        }

        return array_merge($in, $override);
    }

    /** context.id هو wamid رسالة المجموعة، أو (قبل ربطه) صف صادر من المحادثة نفسها يحمل رقم المجموعة */
    private static function contextMatches(WaConversation $conv, WaChoiceSet $set, ?string $replyTo): bool
    {
        if ($replyTo === null || $replyTo === '') {
            return false;
        }
        if ($set->wamid !== null) {
            return hash_equals((string) $set->wamid, $replyTo);
        }
        $row = WaMessage::where('conversation_id', $conv->id)->where('direction', 'out')->where('wamid', $replyTo)->first(['id', 'payload']);

        return $row !== null && (int) data_get($row->payload, '_meta.choice_set_id') === (int) $set->id;
    }

    /** إعادة الفحص من القاعدة الآن: null = ما زال متاحاً، وإلا السبب */
    private static function stillAvailable(array $ref): ?string
    {
        if (!empty($ref['category'])) {
            return Offer::forBot()->where('category', (string) $ref['category'])->exists() ? null : 'offer_gone';
        }
        if (empty($ref['offer_id'])) {
            return null;
        }
        $offer = Offer::forBot()->with(['groups.options.stays', 'options.stays'])->find((int) $ref['offer_id']);
        if (!$offer) {
            return 'offer_gone';
        }
        if (empty($ref['option_ref'])) {
            return null;
        }
        $opt = ChoiceBuilder::sellOption($offer, (string) $ref['option_ref']);
        if (!$opt) {
            return 'option_gone';
        }

        return empty($ref['room']) || isset($opt->availablePrices()[(string) $ref['room']]) ? null : 'room_gone';
    }

    private static function result(?WaChoiceSet $set, string $key, bool $valid, ?string $reason, ?array $option = null): array
    {
        return [
            'set_id' => $set?->id,
            'kind' => $set?->kind,
            'key' => $key,
            'valid' => $valid,
            'reason' => $reason,
            'ref' => (array) ($option['ref'] ?? []),
            'label' => isset($option['title']) ? (string) $option['title'] : null,
        ];
    }
}
