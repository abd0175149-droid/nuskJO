<?php

namespace App\Services;

use App\Enums\HandoffReason;
use App\Models\QuoteRequest;
use App\Models\WaConversation;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\QuoteRequestGuard;
use Illuminate\Support\Carbon;

/**
 * طلب التسعير من مدخلات منظّمة (E36، مستخرج من BotTools::requestQuote): أداة البوت request_quote ونموذج واتساب
 * (FlowResponseHandler) يمرّان من هنا، فيشتركان في الحقول ومنع التكرار (E17) والتدقيق والتحويل لموظف.
 * المدخلات منقّاة قبله (قواعد الأداة، أو القائمة البيضاء للنموذج)، والقصّ هنا بحدود الأعمدة.
 */
final class QuoteRequestFactory
{
    public const TYPES = ['flight', 'package', 'visa', 'hotel', 'transport', 'other'];

    /**
     * @param  'bot'|'flow'  $source
     * @return array{qr: QuoteRequest, duplicate: bool}
     */
    public static function fromInput(WaConversation $conv, array $in, string $source = 'bot'): array
    {
        $type = in_array($in['type'] ?? null, self::TYPES, true) ? $in['type'] : ($source === 'flow' ? 'other' : 'flight');
        $details = mb_substr((string) ($in['details'] ?? ''), 0, 2000);
        if ($source === 'flow') {
            $details = mb_substr('📝 من نموذج واتساب'.($details !== '' ? ' — '.$details : ''), 0, 2000);
        }

        ['qr' => $qr, 'duplicate' => $dup] = QuoteRequestGuard::createOrReuse($conv, 'quote', [
            'conversation_id' => $conv->id,
            'client_id' => $conv->client_id,
            // عميل بلا رقم: نص فارغ (العمود NOT NULL)، والهوية من conversation_id
            'phone' => $conv->phone ?? '',
            'customer_name' => self::customerName($conv),
            'type' => $type,
            // كان القصّ عند 8 أحرف (طول رمز مطار) يقطع أسماء المدن: «المدينة المنورة» ← «المدينة»
            'route_from' => mb_substr((string) ($in['route_from'] ?? ''), 0, 60) ?: null,
            'route_to' => mb_substr((string) ($in['route_to'] ?? ''), 0, 60) ?: null,
            'depart_date' => self::date($in['depart_date'] ?? null),
            'return_date' => self::date($in['return_date'] ?? null),
            'pax_adults' => max(1, (int) ($in['adults'] ?? 1)),
            'pax_children' => max(0, (int) ($in['children'] ?? 0)),
            'pax_infants' => max(0, (int) ($in['infants'] ?? 0)),
            'details' => $details,
            'status' => 'new',
        ]);
        if (!$dup) {
            BotTools::auditQuote($qr, $conv, $source !== 'bot' ? ['source' => $source] : []);
            BotTools::escalate($conv, $qr, HandoffReason::Quote);
        }

        return ['qr' => $qr, 'duplicate' => $dup];
    }

    /** اسم الطلب: العميل المربوط، ثم الاسم المحفوظ، ثم لقب واتساب (غير موثّق) */
    public static function customerName(WaConversation $conv): ?string
    {
        return $conv->client?->name ?: ($conv->display_name ?: $conv->wa_profile_name);
    }

    private static function date(mixed $d): ?string
    {
        if (!is_string($d) || $d === '') {
            return null;
        }
        try {
            return Carbon::parse($d)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
