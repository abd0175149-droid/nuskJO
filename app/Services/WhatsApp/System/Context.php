<?php

namespace App\Services\WhatsApp\System;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Models\Receipt;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaOutbox;
use App\Services\CompanyInfo;
use App\Services\TravelersReport;
use Carbon\CarbonInterface;

/**
 * متغيرات القالب ومدى صلاحية الرسالة لحظة الإرسال (لا لحظة الجدولة): تُقرأ الفاتورة والسند والطلب من القاعدة الآن،
 * فتعديل تاريخ السفر أو سداد المتبقي يلغي التذكير القديم بدل أن يرسله.
 *
 * @return array{valid: bool, reason: ?string, vars: array<string, mixed>, phone?: ?string, client_id?: ?int}
 */
final class Context
{
    public static function for(string $typeKey, string $sourceType, int $sourceId, array $anchor = [], ?CarbonInterface $now = null): array
    {
        $company = (string) (CompanyInfo::all()['name'] ?? '');

        return match ($sourceType) {
            'invoice' => self::invoice($typeKey, $sourceId, $anchor, $company),
            'receipt' => self::receipt($sourceId, $company),
            'quote' => self::quote($sourceId, $anchor, $company),
            'client' => self::client($sourceId, $company),
            'conversation' => self::conversation($sourceId),
            'admin' => self::admin($anchor, $now ?? now()),
            default => self::invalid('مصدر غير معروف'),
        } + ['vars' => []];
    }

    private static function invoice(string $type, int $id, array $anchor, string $company): array
    {
        $inv = Invoice::with(['client:id,name,phone', 'offer:id,title,destination,return_date'])->find($id);
        if (!$inv || $inv->status !== 'approved') {
            return self::invalid('الفاتورة لم تعد معتمدة');
        }
        $trip = $inv->trip_date ? \Illuminate\Support\Carbon::parse($inv->trip_date)->toDateString() : null;
        if (str_starts_with($type, 'trip.') && $type !== 'trip.return' && isset($anchor['date']) && $anchor['date'] !== $trip) {
            return self::invalid('تغيّر تاريخ السفر');
        }
        $remaining = (float) (TravelersReport::remainingFifo(collect([$inv->client_id]))[$inv->id] ?? 0);
        if ($type === 'payment.due') {
            $due = $inv->due_date ? \Illuminate\Support\Carbon::parse($inv->due_date)->toDateString() : null;
            if ($remaining <= 0.0005) {
                return self::invalid('سُدّد المتبقي');
            }
            if (isset($anchor['date']) && $anchor['date'] !== $due) {
                return self::invalid('تغيّر موعد الاستحقاق');
            }
        }
        if ($type === 'trip.return' && isset($anchor['date']) && $anchor['date'] !== optional($inv->offer?->return_date)->toDateString()) {
            return self::invalid('تغيّر تاريخ العودة');
        }

        return ['valid' => true, 'reason' => null, 'phone' => $inv->client_phone ?: $inv->client?->phone, 'client_id' => $inv->client_id, 'vars' => [
            'client_name' => self::firstName($inv->client?->name),
            'invoice_number' => $inv->invoice_number,
            'total' => self::money($inv->total_sell_jod),
            'trip_date' => $trip,
            'due_date' => $inv->due_date ? \Illuminate\Support\Carbon::parse($inv->due_date)->toDateString() : null,
            'remaining' => self::money($remaining),
            'destination' => $inv->offer?->destination ?: ($inv->offer?->title ?: 'وجهتك'),
            'company' => $company,
        ]];
    }

    private static function receipt(int $id, string $company): array
    {
        $r = Receipt::with('client:id,name,phone')->find($id);
        if (!$r || $r->status !== 'approved') {
            return self::invalid('السند لم يعد معتمداً');
        }
        $remaining = array_sum(TravelersReport::remainingFifo(collect([$r->client_id])));

        return ['valid' => true, 'reason' => null, 'phone' => $r->client?->phone, 'client_id' => $r->client_id, 'vars' => [
            'client_name' => self::firstName($r->client?->name),
            'receipt_number' => $r->receipt_number,
            'amount' => self::money($r->amount_jod),
            'remaining' => self::money($remaining),
            'company' => $company,
        ]];
    }

    private static function quote(int $id, array $anchor, string $company): array
    {
        $q = QuoteRequest::find($id);
        if (!$q || $q->status !== 'priced' || !$q->quoted_at) {
            return self::invalid('الطلب لم يعد بانتظار رد العميل');
        }
        if ($q->conversation_id && WaMessage::where('conversation_id', $q->conversation_id)->where('direction', 'in')->where('created_at', '>', $q->quoted_at)->exists()) {
            return self::invalid('رد العميل بعد السعر');
        }
        $conv = $q->conversation_id ? WaConversation::find($q->conversation_id) : null;

        return ['valid' => true, 'reason' => null, 'phone' => $q->phone ?: $conv?->phone, 'client_id' => $q->client_id ?: $conv?->client_id,
            'conversation_id' => $conv?->id, 'vars' => [
                'client_name' => self::firstName($q->customer_name ?: $conv?->label()),
                'request_number' => $q->request_number,
                'company' => $company,
            ]];
    }

    private static function client(int $id, string $company): array
    {
        $c = Client::find($id);
        if (!$c || !$c->is_active || blank($c->phone)) {
            return self::invalid('العميل غير فعّال أو بلا هاتف');
        }

        return ['valid' => true, 'reason' => null, 'phone' => $c->phone, 'client_id' => $c->id, 'vars' => [
            'client_name' => self::firstName($c->name), 'company' => $company,
        ]];
    }

    private static function conversation(int $id): array
    {
        $conv = WaConversation::find($id);
        if (!$conv || $conv->status === 'merged') {
            return self::invalid('المحادثة لم تعد موجودة');
        }

        return ['valid' => true, 'reason' => null, 'phone' => $conv->phone, 'client_id' => $conv->client_id, 'conversation_id' => $conv->id,
            'vars' => ['client_name' => self::firstName($conv->client?->name ?: $conv->wa_profile_name)]];
    }

    private static function admin(array $anchor, CarbonInterface $now): array
    {
        $day = isset($anchor['date']) ? \Illuminate\Support\Carbon::parse($anchor['date'], 'Asia/Amman') : $now->copy()->setTimezone('Asia/Amman');
        [$from, $to] = [$day->copy()->startOfDay()->setTimezone(config('app.timezone')), $day->copy()->endOfDay()->setTimezone(config('app.timezone'))];

        return ['valid' => true, 'reason' => null, 'vars' => [
            'date' => $day->toDateString(),
            'conversations' => WaConversation::whereBetween('last_inbound_at', [$from, $to])->count(),
            'needs_attention' => WaConversation::where('needs_attention', true)->where('status', 'open')->count(),
            'invoices' => Invoice::where('status', 'approved')->whereBetween('approved_at', [$from, $to])->count(),
            'receipts' => Receipt::where('status', 'approved')->whereBetween('approved_at', [$from, $to])->count(),
            'collected' => self::money(Receipt::where('status', 'approved')->whereBetween('approved_at', [$from, $to])->sum('amount_jod')),
            'system_sent' => WaOutbox::where('status', WaOutbox::SENT)->where('is_test', false)->whereBetween('sent_at', [$from, $to])->count(),
            'system_failed' => WaOutbox::whereIn('status', [WaOutbox::FAILED, WaOutbox::EXPIRED])->where('is_test', false)->whereBetween('updated_at', [$from, $to])->count(),
        ]];
    }

    /** الاسم الأول وحده في الرسالة (لا الاسم الرباعي ولا الرمز) */
    public static function firstName(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '' || preg_match('/^\+?\d[\d\s]+$/', $name)) {
            return 'عميلنا العزيز';
        }

        return mb_substr(preg_split('/\s+/u', $name)[0] ?? $name, 0, 40);
    }

    private static function money(mixed $v): string
    {
        $n = round((float) $v, 3);

        return rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.') ?: '0';
    }

    private static function invalid(string $reason): array
    {
        return ['valid' => false, 'reason' => $reason, 'vars' => []];
    }
}
