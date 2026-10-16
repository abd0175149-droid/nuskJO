<?php

namespace App\Services\WhatsApp;

use App\Models\Offer;
use App\Models\WaQrLink;

/**
 * مرجع العرض في الرسالة المعبأة (E38): [N-45/Q7] لرمز عرض، و[N-45] لرابط بلا رمز، و[Q12] لرمز عام.
 * ASCII وحده وبحرف N كبير: «n-45» و«غرفة N 4» وأرقام الهواتف لا تطابق. المرجع مدخل غير موثوق يكتبه أي أحد،
 * فأقصى ما يفعله تلميح للبوت ووسم موضوع، ولا يكشف شيئاً لا يكشفه get_offers.
 */
final class OfferRef
{
    private const OFFER = '/(?<![A-Za-z0-9])\[?N-(\d{1,7})(?:\/Q(\d{1,7}))?\]?/';

    private const GENERAL = '/\[Q(\d{1,7})\]/';

    /** @return array{offer_id: ?int, qr_link_id: ?int} */
    public static function parse(string $body): array
    {
        if (preg_match(self::OFFER, $body, $m)) {
            return ['offer_id' => (int) $m[1], 'qr_link_id' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null];
        }
        if (preg_match(self::GENERAL, $body, $m)) {
            return ['offer_id' => null, 'qr_link_id' => (int) $m[1]];
        }

        return ['offer_id' => null, 'qr_link_id' => null];
    }

    /** المرجع المبني للرسالة المعبأة: [N-45/Q7] أو [Q12] */
    public static function token(?int $offerId, int $linkId): string
    {
        return $offerId ? "[N-{$offerId}/Q{$linkId}]" : "[Q{$linkId}]";
    }

    /**
     * التحقق وجوداً: رمز لا يخص العرض المذكور يُهمل ويُكتفى بالعرض، ورمز عام يُقبل بلا عرض.
     *
     * @return array{offer: ?Offer, link: ?WaQrLink}
     */
    public static function resolve(array $ref): array
    {
        $offer = $ref['offer_id'] ? Offer::find($ref['offer_id']) : null;
        $link = $ref['qr_link_id'] ? WaQrLink::find($ref['qr_link_id']) : null;
        if ($link && $link->offer_id !== ($offer?->id)) {
            $link = null;
        }

        return ['offer' => $offer, 'link' => $link];
    }

    /** هل بقي المرجع في الرسالة التي أعادتها ميتا؟ */
    public static function matches(string $returned, ?int $offerId, int $linkId): bool
    {
        $ref = self::parse($returned);

        return $ref['offer_id'] === $offerId && $ref['qr_link_id'] === $linkId;
    }
}
