<?php

namespace App\Services\Retention;

use App\Models\Client;
use App\Models\WaConversation;
use App\Services\TravelersReport;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * حق الاطّلاع (E13.8): نسخة من بيانات الشخص بقوائم حقول مسموحة صريحة. لا payload ولا wamid ولا أسماء
 * موظفين ولا رقم هوية كامل. ما ليس في FIELDS لا يُصدَّر مهما أُضيف للجداول لاحقاً.
 */
final class SubjectExport
{
    public const FIELDS = [
        'client' => ['name', 'code', 'phone', 'email', 'city', 'country', 'nationality', 'id_number'],
        'conversation' => ['created_at', 'last_message_at'],
        'message' => ['created_at', 'direction', 'source', 'msg_type', 'body'],
        'note' => ['note', 'created_at'],
        'quote' => ['request_number', 'type', 'route_from', 'route_to', 'depart_date', 'return_date', 'pax_adults', 'pax_children', 'pax_infants', 'status', 'quoted_price_jod'],
        'invoice' => ['invoice_number', 'invoice_date', 'trip_date', 'total_sell_jod', 'status'],
        'receipt' => ['receipt_number', 'receipt_date', 'amount_jod'],
    ];

    /** @return array{data: array<string, mixed>, counts: array<string, int>} */
    public function build(?Client $client, ?string $canonicalPhone): array
    {
        $convs = WaConversation::query()->whereNotIn('status', ['erased'])
            ->where(function ($q) use ($client, $canonicalPhone) {
                $q->whereRaw('1 = 0');
                if ($client) {
                    $q->orWhere('client_id', $client->id);
                }
                if ($canonicalPhone) {
                    $q->orWhere('phone', $canonicalPhone);
                }
            })->get();
        $ids = $convs->pluck('id')->all();

        $messages = DB::table('wa_messages')->whereIn('conversation_id', $ids)->orderBy('id')->get(self::FIELDS['message'])
            ->map(fn ($m) => ['created_at' => $m->created_at, 'direction' => $m->direction, 'source' => $m->direction === 'in' ? 'العميل' : 'نُسك',
                'msg_type' => $m->msg_type, 'body' => $m->body]);
        $notes = DB::table('wa_customer_notes')->whereIn('conversation_id', $ids)->orderBy('id')->get(self::FIELDS['note']);
        $quotes = DB::table('quote_requests')->whereIn('conversation_id', $ids)
            ->when($client, fn ($q) => $q->orWhere('client_id', $client->id))->orderBy('id')->get(self::FIELDS['quote']);
        $invoices = $client ? DB::table('invoices')->where('client_id', $client->id)->orderBy('invoice_date')->get(['id', ...self::FIELDS['invoice']]) : collect();
        $remaining = $client ? TravelersReport::remainingFifo(collect([$client->id])) : [];
        $receipts = $client ? DB::table('receipts')->where('client_id', $client->id)->where('status', 'approved')->orderBy('receipt_date')->get(self::FIELDS['receipt']) : collect();

        $data = [
            'generated_at' => now()->toIso8601String(),
            'client' => $client ? $this->clientFields($client) : null,
            'conversations' => $convs->map(fn ($c) => ['created_at' => $c->created_at?->toIso8601String(), 'last_message_at' => $c->last_message_at?->toIso8601String()])->values(),
            'messages' => $messages->values(),
            'notes' => $notes->values(),
            'quote_requests' => $quotes->values(),
            'invoices' => $invoices->map(fn ($i) => collect((array) $i)->only(self::FIELDS['invoice'])->all() + ['remaining_jod' => $remaining[$i->id] ?? null])->values(),
            'receipts' => $receipts->values(),
        ];

        return ['data' => $data, 'counts' => [
            'conversations' => $convs->count(), 'messages' => $messages->count(), 'notes' => $notes->count(),
            'quote_requests' => $quotes->count(), 'invoices' => $invoices->count(), 'receipts' => $receipts->count(),
        ]];
    }

    private function clientFields(Client $c): array
    {
        $out = collect($c->only(self::FIELDS['client']))->all();
        // رقم الهوية مقنّعاً بآخر 4
        $digits = (string) ($out['id_number'] ?? '');
        $out['id_number'] = $digits !== '' ? '••••'.mb_substr($digits, -4) : null;

        return $out;
    }

    public static function resolveClient(?string $clientId, ?string $canonicalPhone): ?Client
    {
        if ($clientId) {
            return Client::find($clientId);
        }

        return $canonicalPhone ? PhoneNormalizer::findClient($canonicalPhone) : null;
    }
}
