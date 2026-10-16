<?php

namespace App\Services\WhatsApp;

use App\Models\QuoteRequest;
use App\Models\WaConversation;
use App\Services\Integrations\IntegrationOutbox;
use App\Support\FeatureFlags;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * منع تكرار طلبات التسعير والحجز (E17.8): مسار التكرار الحقيقي أن يستدعي النموذج الأداة مرتين بالمدخلات نفسها
 * في الحلقة نفسها، أو أن يعيد العميل الطلب بعد وصول الأول. المكرر يعيد رقم الطلب الموجود بلا إشعار ثانٍ.
 *
 * المفتاح: الوارد المصدر + النوع + البصمة، فطلبان مختلفان في رسالة واحدة (عمّان–جدة وعمّان–إسطنبول) طلبان.
 * والنافذة: طلب «جديد» للعرض نفسه (أو المسار والتاريخ نفسيهما) خلال 30 دقيقة.
 */
final class QuoteRequestGuard
{
    public const WINDOW_MINUTES = 30;

    private const NUMBER_RETRIES = 3;

    private static ?bool $available = null;

    public static function enabled(): bool
    {
        return FeatureFlags::on('quote_dedupe') && self::available();
    }

    private static function available(): bool
    {
        return self::$available ??= Schema::hasColumn('quote_requests', 'dedupe_key');
    }

    /**
     * @param  'quote'|'booking'  $kind
     * @return array{qr: QuoteRequest, duplicate: bool}
     */
    public static function createOrReuse(WaConversation $conv, string $kind, array $attrs): array
    {
        if (!self::enabled()) {
            return ['qr' => self::create($attrs), 'duplicate' => false];
        }

        $fingerprint = self::fingerprint($kind, $attrs);
        $source = ReplyRun::current()?->uptoId
            ?: (int) $conv->messages()->where('direction', 'in')->max('id');
        $key = substr(sha1($source.'|'.$kind.'|'.$fingerprint), 0, 40);

        $existing = self::existing($conv, $kind, $attrs, $key, $fingerprint);
        if ($existing) {
            return ['qr' => $existing, 'duplicate' => true];
        }

        try {
            $qr = self::create($attrs + ['source_msg_id' => $source ?: null, 'dedupe_key' => $key]);
        } catch (UniqueConstraintViolationException $e) {
            // سباق نادر بين عاملين على المفتاح نفسه: الآخر أنشأه
            $raced = QuoteRequest::where('conversation_id', $conv->id)->where('dedupe_key', $key)->first();
            if (!$raced) {
                throw $e;
            }

            return ['qr' => $raced, 'duplicate' => true];
        }

        return ['qr' => $qr, 'duplicate' => false];
    }

    /** البصمة: ما يجعل الطلب طلباً آخر */
    public static function fingerprint(string $kind, array $a): string
    {
        $parts = $kind === 'booking'
            ? [$a['offer_id'] ?? '', data_get($a, 'offer_selection.sel') ? json_encode(data_get($a, 'offer_selection.sel')) : '',
                $a['pax_adults'] ?? 1, $a['pax_children'] ?? 0, $a['pax_infants'] ?? 0, self::day($a['depart_date'] ?? null)]
            : [$a['type'] ?? '', TextNormalizer::forSearch($a['route_from'] ?? null) ?? '', TextNormalizer::forSearch($a['route_to'] ?? null) ?? '',
                self::day($a['depart_date'] ?? null), $a['pax_adults'] ?? 1];

        return implode('|', array_map('strval', $parts));
    }

    private static function existing(WaConversation $conv, string $kind, array $attrs, string $key, string $fingerprint): ?QuoteRequest
    {
        // الرسالة نفسها: النموذج استدعى الأداة مرتين بالمدخلات نفسها
        $same = QuoteRequest::where('conversation_id', $conv->id)->where('dedupe_key', $key)->first();
        if ($same) {
            return $same;
        }

        // العميل أعاد الطلب: «جديد» خلال 30 دقيقة للعرض نفسه، أو للمسار والتاريخ نفسيهما في التسعير الحر
        $recent = QuoteRequest::where('conversation_id', $conv->id)
            ->where('status', 'new')
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->when($kind === 'booking', fn ($q) => $q->where('offer_id', $attrs['offer_id'] ?? 0), fn ($q) => $q->whereNull('offer_id'))
            ->orderByDesc('id')
            ->get();

        return $kind === 'booking'
            ? $recent->first()
            : $recent->first(fn (QuoteRequest $qr) => self::fingerprint('quote', $qr->getAttributes()) === $fingerprint);
    }

    /** الترقيم max+1 ليس ذرياً: تصادم الرقم يُعاد حتى 3 مرات */
    private static function create(array $attrs): QuoteRequest
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($attrs) {
                    $qr = QuoteRequest::create(['request_number' => QuoteRequest::nextNumber()] + $attrs);
                    // E40: الصندوق الصادر في المعاملة نفسها؛ فشله لا يُفشل طلب العميل (نقطة حفظ داخلية)
                    IntegrationOutbox::quoteCreated($qr);

                    return $qr;
                });
            } catch (UniqueConstraintViolationException $e) {
                $onNumber = str_contains($e->getMessage(), 'request_number');
                if (!$onNumber || $attempt >= self::NUMBER_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    private static function day(mixed $date): string
    {
        if (!$date) {
            return '';
        }

        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
    }
}
