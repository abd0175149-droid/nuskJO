<?php

namespace App\Services\WhatsApp\Bot;

use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Models\WaAdminNumber;
use App\Models\WaConversation;
use Illuminate\Support\Carbon;

/**
 * ملف المرسل الذي تُقرر به أدوات البوت (ToolPolicy)، من الأقوى إلى الأضعف:
 *   admin    رقم إدارة مسجّل (المساعد الإداري، AdminTools)
 *   traveler عميل مربوط بالهاتف له فاتورة معتمدة واليوم بين trip_date−1 وتاريخ العودة+1 (أو trip_date+15 بلا عودة)
 *   client   عميل مربوط بالهاتف (financialClientId)
 *   lead     له طلب تسعير مفتوح (new أو priced) خلال 60 يوماً
 *   guest    أي مرسل آخر
 * الربط بالعميل من الهاتف القادم من webhook موقّع وحده، فلا يرقى أحد ملفه بادعاء.
 */
final class SenderProfile
{
    public const PROFILES = ['guest', 'lead', 'client', 'traveler', 'admin'];

    public const LABELS = ['guest' => 'زائر', 'lead' => 'عميل محتمل', 'client' => 'عميل', 'traveler' => 'مسافر الآن', 'admin' => 'رقم إدارة'];

    public static function for(WaConversation $conv): string
    {
        if ($conv->is_admin_chat || ($conv->phone && WaAdminNumber::forPhone($conv->phone))) {
            return 'admin';
        }
        $clientId = $conv->financialClientId();
        if ($clientId !== null) {
            return self::travelling($clientId) ? 'traveler' : 'client';
        }
        $openQuote = QuoteRequest::query()->where('conversation_id', $conv->id)->whereIn('status', ['new', 'priced'])
            ->where('created_at', '>=', now()->subDays(60))->exists();

        return $openQuote ? 'lead' : 'guest';
    }

    public static function travelling(int $clientId): bool
    {
        $today = now()->setTimezone('Asia/Amman')->startOfDay();

        return Invoice::query()->with('offer:id,return_date')->where('client_id', $clientId)->where('status', 'approved')
            ->whereNotNull('trip_date')->where('trip_date', '<=', $today->copy()->addDay()->toDateString())
            ->where('trip_date', '>=', $today->copy()->subDays(60)->toDateString())
            ->get(['id', 'trip_date', 'offer_id'])
            ->contains(function (Invoice $i) use ($today) {
                $start = Carbon::parse($i->trip_date, 'Asia/Amman')->subDay();
                $end = $i->offer?->return_date ? Carbon::parse($i->offer->return_date, 'Asia/Amman')->addDay() : Carbon::parse($i->trip_date, 'Asia/Amman')->addDays(15);

                return $today->betweenIncluded($start->startOfDay(), $end->endOfDay());
            });
    }
}
