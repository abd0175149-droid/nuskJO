<?php

namespace App\Services\Integrations;

use App\Jobs\DeliverIntegrationWebhook;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationOutboxRow;
use App\Models\Invoice;
use App\Models\QuoteRequest;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * الصندوق الصادر للتكاملات (E40): يُستدعى داخل معاملة الحدث نفسه، فالمعاملة المتراجعة لا ترسل شيئاً، والحدث
 * الملتزم لا يضيع ولو توقف العامل (المُصالِح يجدوله). صف لكل وجهة نشطة مشتركة، فريد بـ idempotency_key.
 *
 * فشل التسجيل لا يُفشل الحدث الأصلي (اعتماد فاتورة أو طلب عميل أهم من التكامل): نقطة حفظ داخلية تتراجع وحدها
 * ويُكتب سطر سجل. والتدقيق للحدث الأصلي (E09)، لا يُكرَّر هنا.
 */
final class IntegrationOutbox
{
    public static function enabled(): bool
    {
        return FeatureFlags::on('integration_outbox');
    }

    /**
     * @param  string  $key  ثابت عبر المحاولات (webhook-id عند المستقبل)، مثل invoice_15_approved_1760000000
     * @param  IntegrationEndpoint|null  $only  وجهة بعينها (ping من الصفحة) بدل كل المشتركين
     * @param  bool  $dispatch  false لـ ping: يُرسل متزامناً من المتحكم فتُعرض نتيجته فوراً
     * @return list<int> معرّفات الصفوف المكتوبة
     */
    public static function record(string $event, string $key, array $data, ?IntegrationEndpoint $only = null, bool $dispatch = true): array
    {
        if (!in_array($event, IntegrationEvents::ALLOWED, true)) {
            throw new \InvalidArgumentException("حدث تكامل غير معرّف: {$event}");
        }

        try {
            if (!self::enabled()) {
                return [];
            }
            $endpoints = $only
                ? collect([$only])
                : IntegrationEndpoint::active()->get()->filter(fn (IntegrationEndpoint $e) => $e->subscribedTo($event));
            if ($endpoints->isEmpty()) {
                return [];
            }

            return DB::transaction(function () use ($endpoints, $event, $key, $data, $dispatch) {
                $ids = [];
                foreach ($endpoints as $endpoint) {
                    $payload = IntegrationEvents::envelope($event, $key, IntegrationEvents::whitelist($event, $data, (bool) $endpoint->include_amounts));
                    $inserted = IntegrationOutboxRow::query()->insertOrIgnore([
                        'endpoint_id' => $endpoint->id,
                        'event' => $event,
                        'idempotency_key' => mb_substr($key, 0, 120),
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'status' => IntegrationOutboxRow::PENDING,
                        'attempts' => 0,
                        'next_attempt_at' => now(),
                        'dispatched_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    // التسجيل المكرر للحدث نفسه (unique) لا يكرر الصف ولا الوظيفة
                    if ($inserted !== 1) {
                        continue;
                    }
                    $id = (int) IntegrationOutboxRow::query()->where('endpoint_id', $endpoint->id)->where('idempotency_key', mb_substr($key, 0, 120))->value('id');
                    $ids[] = $id;
                    if ($dispatch) {
                        DeliverIntegrationWebhook::dispatchFor($id);
                    }
                }

                return $ids;
            });
        } catch (\Throwable $e) {
            Log::error('integrations.record_failed', ['event' => $event, 'exception' => $e::class]);

            return [];
        }
    }

    // ==================== مواضع الاستدعاء: كلها داخل معاملة الحدث نفسه ====================

    /** طلب تسعير أو حجز من البوت (QuoteRequestGuard::create). لا details ولا هاتف ولا اسم كتبه العميل */
    public static function quoteCreated(QuoteRequest $qr): void
    {
        self::safely('quote_request.created', fn () => self::record('quote_request.created', 'quote_'.$qr->id.'_created', [
            'request_number' => $qr->request_number,
            'type' => $qr->type,
            'status' => $qr->status,
            'offer_id' => $qr->offer_id,
            'offer_title' => $qr->offer_id ? $qr->offer()->value('title') : null,
            'topics' => $qr->conversation_id
                ? DB::table('wa_conversation_topics')->where('conversation_id', $qr->conversation_id)->orderBy('topic')->pluck('topic')->all()
                : [],
            'created_at' => ($qr->created_at ?? now())->format(DATE_ATOM),
        ]));
    }

    /** فاتورة عرض أُنشئت من الطلب (won). ليس اعتماداً محاسبياً */
    public static function quoteConverted(QuoteRequest $qr, Invoice $invoice): void
    {
        self::safely('quote_request.converted', fn () => self::record('quote_request.converted', 'quote_'.$qr->id.'_converted_'.$invoice->id, [
            'request_number' => $qr->request_number,
            'invoice_number' => $invoice->invoice_number,
            'offer_id' => $invoice->offer_id,
            'created_at' => now()->format(DATE_ATOM),
        ]));
    }

    /** اعتماد فاتورة: إعادة الاعتماد بعد «فتح للتعديل» حدث جديد لأن approved_at جديد. لا تكلفة ولا ربح ولا وكيل ولا بنود */
    public static function invoiceApproved(Invoice $invoice): void
    {
        self::safely('invoice.approved', function () use ($invoice) {
            $approvedAt = $invoice->approved_at ?? now();
            self::record('invoice.approved', 'invoice_'.$invoice->id.'_approved_'.$approvedAt->getTimestamp(), [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'trip_date' => $invoice->trip_date?->toDateString(),
                'offer_id' => $invoice->offer_id,
                'client_code' => $invoice->client()->value('code'),
                'approved_at' => $approvedAt->format(DATE_ATOM),
                'total_sell_jod' => round((float) $invoice->total_sell_jod, 3),
            ]);
        });
    }

    /** العلم مطفأ: لا استعلام إضافي. وأي خطأ في بناء الحمولة لا يصل الحدث الأصلي */
    private static function safely(string $event, callable $record): void
    {
        try {
            if (self::enabled()) {
                $record();
            }
        } catch (\Throwable $e) {
            Log::error('integrations.record_failed', ['event' => $event, 'exception' => $e::class]);
        }
    }
}
