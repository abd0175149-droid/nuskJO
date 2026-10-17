<?php

namespace App\Services\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaQuickReply;
use App\Services\CompanyInfo;

/**
 * ملء متغيرات الرد الجاهز في الخادم (E17.7) من المحادثة نفسها. المتغير بلا قيمة يبقى {{var}} حرفياً ويُدرج
 * في missing، والإرسال يُرفض ما بقي متغير (حارس send). لا رصيد ولا متغير مالي إلا {{quoted_price}} لمن يملك quotes.price.
 */
final class QuickReplyRenderer
{
    public const RECENT_DAYS = 30;

    /** @return array{text: string, missing: list<string>} */
    public static function render(WaQuickReply $reply, WaConversation $c, User $u): array
    {
        $values = self::values($c, $u);
        $missing = [];

        $text = preg_replace_callback('/\{\{\s*([^{}]*?)\s*\}\}/u', function ($m) use ($values, &$missing) {
            $value = $values[$m[1]] ?? null;
            if ($value === null || $value === '') {
                $missing[] = $m[1];

                return '{{'.$m[1].'}}';
            }

            return $value;
        }, (string) $reply->body);

        return ['text' => $text, 'missing' => array_values(array_unique($missing))];
    }

    /** @return array<string, ?string> */
    private static function values(WaConversation $c, User $u): array
    {
        $company = CompanyInfo::all();
        // الاسم من العميل المربوط وحده: لقب واتساب نص يكتبه العميل غير موثّق (E11)
        $client = $c->client;
        $latest = QuoteRequest::where('conversation_id', $c->id)->where('created_at', '>=', now()->subDays(self::RECENT_DAYS))->latest('id')->first();
        $priced = QuoteRequest::where('conversation_id', $c->id)->where('created_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->whereNotNull('quoted_price_jod')->latest('id')->first();

        return [
            'name' => $client?->name,
            // كود العميل لمحادثة معروفة الرقم ومربوطة فقط (financialClientId)
            'client_code' => $c->financialClientId() ? $client?->code : null,
            'staff_name' => $u->name,
            'company_name' => $company['name'] ?? null,
            'company_phone' => $company['phone'] ?? null,
            'request_number' => $latest?->request_number,
            'offer_title' => $latest?->offer?->title,
            'quoted_price' => $priced && $u->can('quotes.price') ? number_format((float) $priced->quoted_price_jod, 3).' د.أ' : null,
        ];
    }
}
