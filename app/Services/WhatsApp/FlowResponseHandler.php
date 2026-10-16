<?php

namespace App\Services\WhatsApp;

use App\Models\WaChoiceSet;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\QuoteRequestFactory;
use Illuminate\Support\Carbon;

/**
 * ردّ نموذج طلب التسعير (E36، nfm_reply من Flow ثابت بلا نقطة نهاية). بالكود وحده بلا نموذج ذكاء:
 * 1) response_json بحد 4KB 2) flow_token يطابق مجموعة quote_flow للمحادثة نفسها، غير مستهلكة ولا منتهية
 * 3) قائمة بيضاء للحقول وكل ما سواها يُسقط، والتواريخ من اليوم حتى سنتين، والأعداد في مداها، والنصوص مقصوصة
 * 4) تنقية التفاصيل من أي تتابع يشبه رقم جواز أو هوية أو هاتف 5) QuoteRequestFactory كأداة request_quote
 * (منع التكرار E17 والتحويل لموظف) 6) استهلاك الرمز 7) نص الوارد ملخص مقروء للموظف 8) تأكيد نظامي يبنيه الكود.
 * رمز مجهول أو مستهلك أو من محادثة أخرى: لا طلب، والمحادثة «تحتاج تدخّلاً» بسبب flow_invalid.
 */
final class FlowResponseHandler
{
    public const FIELDS = ['type', 'route_from', 'route_to', 'depart_date', 'return_date', 'adults', 'children', 'infants', 'details'];

    public const TYPE_LABELS = ['flight' => 'طيران', 'package' => 'باقة', 'visa' => 'تأشيرة', 'hotel' => 'فندق', 'transport' => 'نقل', 'other' => 'أخرى'];

    public const SCRUBBED = '[حُذف رقم]';

    public static function handle(WaConversation $conv, InboundMessage $in, WaMessage $message): void
    {
        $reply = (array) $in->flowReply;
        $set = self::set($conv, $reply);
        if ($set === null) {
            self::invalid($conv, $message, 'token');

            return;
        }
        // الرد نفسه مرة ثانية (أُرسل النموذج مرتين أو أعادته ميتا): الطلب الأول يكفي، بلا تأكيد ثانٍ
        if ($set->consumed_at !== null) {
            $first = (int) data_get($set->options, '0.ref.quote_request_id');
            self::record($message, ['set_id' => $set->id, 'valid' => true, 'duplicate' => true] + ($first ? ['quote_request_id' => $first] : []), null);
            ReplyState::advance($conv, $message->id);

            return;
        }

        [$data, $scrubbed] = self::clean((array) ($reply['response'] ?? []));
        ['qr' => $qr, 'duplicate' => $dup] = QuoteRequestFactory::fromInput($conv, $data, 'flow');
        $set->forceFill([
            'consumed_at' => now(),
            'options' => [['key' => 'form', 'title' => InteractiveReply::FLOW_CTA, 'ref' => ['quote_request_id' => $qr->id]]],
        ])->save();
        self::record($message, ['set_id' => $set->id, 'valid' => true, 'quote_request_id' => $qr->id, 'request_number' => $qr->request_number,
            'duplicate' => $dup, 'scrubbed' => $scrubbed], self::summary($data));
        ReplyState::advance($conv, $message->id);
        WaLog::info('wa.flow.quote', ['conv_id' => $conv->id, 'set_id' => $set->id, 'message_id' => $message->id, 'count' => $dup ? 0 : 1]);

        if (!$dup) {
            // ميتا توصي بملخص بعد اكتمال النموذج: نص يبنيه الكود برقم الطلب وحده
            WhatsAppChannel::sendText($conv, "وصل طلبك رقم {$qr->request_number}، وسيعود إليك موظف بالسعر النهائي وتأكيد التوفّر.", 'system',
                sendKey: SendKey::flow((int) $message->id));
        }
    }

    /**
     * القائمة البيضاء والتحقق والتنقية. @return array{0: array, 1: bool} [الحقول المقبولة، هل حُذف رقم]
     */
    public static function clean(array $response): array
    {
        $scrubbed = false;
        $text = function (mixed $v, int $max) use (&$scrubbed): ?string {
            if (!is_string($v) && !is_numeric($v)) {
                return null;
            }
            // محارف التحكم والاتجاه تُسقط، والأسطر تبقى في التفاصيل وحدها
            $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', $max > 60 ? "\n" : ' ', (string) $v) ?? '';
            $clean = preg_replace('/[ \t]+/u', ' ', $clean) ?? $clean;
            $clean = preg_replace("/\n{2,}/u", "\n", $clean) ?? $clean;
            $out = self::scrub(trim($clean), $scrubbed);

            return $out !== '' ? mb_substr($out, 0, $max) : null;
        };
        $int = function (mixed $v, int $min, int $max): ?int {
            if (is_string($v) && preg_match('/^\s*\d{1,3}\s*$/', $v)) {
                $v = (int) $v;
            }

            return is_int($v) && $v >= $min && $v <= $max ? $v : null;
        };

        $data = array_filter([
            'type' => is_string($response['type'] ?? null) && isset(self::TYPE_LABELS[$response['type']]) ? $response['type'] : 'other',
            'route_from' => $text($response['route_from'] ?? null, 60),
            'route_to' => $text($response['route_to'] ?? null, 60),
            'depart_date' => self::date($response['depart_date'] ?? null),
            'return_date' => self::date($response['return_date'] ?? null),
            'adults' => $int($response['adults'] ?? null, 1, 20) ?? 1,
            'children' => $int($response['children'] ?? null, 0, 20) ?? 0,
            'infants' => $int($response['infants'] ?? null, 0, 20) ?? 0,
            'details' => $text($response['details'] ?? null, 300),
        ], fn ($v) => $v !== null);
        if (isset($data['depart_date'], $data['return_date']) && $data['return_date'] < $data['depart_date']) {
            unset($data['return_date']);
        }

        return [$data, $scrubbed];
    }

    /**
     * تتابع يشبه رقم جواز (حرفان كحد أقصى ثم 6 إلى 9 أرقام)، أو 9 أرقام فأكثر ولو فصلتها مسافات أو شرطات (هوية أو هاتف).
     * التاريخ (8 أرقام) يبقى.
     */
    public static function scrub(string $text, bool &$scrubbed = false): string
    {
        $out = preg_replace('/(?<![A-Za-z0-9])[A-Za-z]{0,2}\d{6,9}(?![0-9])/u', self::SCRUBBED, $text) ?? $text;
        $out = preg_replace_callback('/\+?\d[\d \-]{7,}\d/u', fn ($m) => strlen(preg_replace('/\D/', '', $m[0]) ?? '') >= 9 ? self::SCRUBBED : $m[0], $out) ?? $out;
        if ($out !== $text) {
            $scrubbed = true;
        }

        return $out;
    }

    /** ملخص مقروء للموظف (نص الوارد في الخيط): الحقول المنقّاة وحدها */
    public static function summary(array $d): string
    {
        $route = trim(($d['route_from'] ?? '').(isset($d['route_from']) || isset($d['route_to']) ? ' ← ' : '').($d['route_to'] ?? ''));
        $dates = trim(($d['depart_date'] ?? '').(isset($d['return_date']) ? ' إلى '.$d['return_date'] : ''));
        $pax = 'بالغون '.($d['adults'] ?? 1).(!empty($d['children']) ? '، أطفال '.$d['children'] : '').(!empty($d['infants']) ? '، رضّع '.$d['infants'] : '');

        return implode(' — ', array_filter([
            '📝 نموذج طلب تسعير: '.(self::TYPE_LABELS[$d['type'] ?? 'other'] ?? 'أخرى'),
            $route !== '' ? $route : null,
            $dates !== '' ? $dates : null,
            $pax,
            $d['details'] ?? null,
        ]));
    }

    /** مجموعة quote_flow للمحادثة نفسها برمزها، غير منتهية؛ والمستهلكة تعود ليُعرف المكرر */
    private static function set(WaConversation $conv, array $reply): ?WaChoiceSet
    {
        $token = $reply['token'] ?? null;
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9]{32}$/', $token) || !is_array($reply['response'] ?? null)) {
            return null;
        }
        $set = WaChoiceSet::where('nonce', $token)->where('kind', 'quote_flow')->first();
        if (!$set || (int) $set->conversation_id !== (int) $conv->id || !hash_equals((string) $set->nonce, $token)) {
            return null;
        }

        return $set->consumed_at === null && $set->isExpired() ? null : $set;
    }

    private static function invalid(WaConversation $conv, WaMessage $message, string $why): void
    {
        // النص كما بناه E11 (بلا flow_token)، منقّى من أي رقم يشبه جوازاً أو هوية
        self::record($message, ['valid' => false, 'reason' => $why], self::scrub((string) $message->body));
        WaLog::warning('wa.flow.invalid', ['conv_id' => $conv->id, 'message_id' => $message->id, 'reason' => $why]);
        ReplyState::markUnserved($conv, 'flow_invalid');
    }

    /** _flow في الحمولة، والنص ملخص بلا رمز ولا حقول خام. الحمولة الخام لميتا (response_json) تُحذف من الصف */
    private static function record(WaMessage $message, array $flow, ?string $body): void
    {
        $payload = (array) ($message->payload ?? []);
        if (isset($payload['interactive']['nfm_reply'])) {
            unset($payload['interactive']['nfm_reply']['response_json']);
        }
        $payload['_flow'] = $flow;
        // عبر Eloquent لا query builder: خطاف WaMessage يعيد body_norm، فلا يبقى في فهرس البحث (E30) نص الحمولة الخام
        $message->forceFill(['payload' => $payload] + ($body !== null ? ['body' => mb_substr($body, 0, 1000)] : []))->save();
    }

    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            $d = Carbon::createFromFormat('!Y-m-d', $v);
        } catch (\Throwable) {
            return null;
        }
        if ($d === false || $d->format('Y-m-d') !== $v || $d->lt(today()) || $d->gt(today()->addYears(2))) {
            return null;
        }

        return $v;
    }
}
