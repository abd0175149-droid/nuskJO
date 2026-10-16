<?php

namespace App\Services\Integrations;

/**
 * عقد الأحداث الصادرة (E40): المصدر الوحيد للأحداث وحقولها البيضاء، تشتق منه الواجهة والاختبار والتوثيق
 * (docs/integrations/webhooks.md). أي حقل غير معلن هنا يسقط عند التسجيل ولو مُرّر خطأً.
 *
 * ممنوع أبداً: نص رسالة، وتفاصيل طلب كتبها العميل، وهاتف، واسم كتبه العميل، وجواز، وتكلفة وربح ووكيل وبنود.
 * المبلغ (total_sell_jod) لا يخرج إلا لوجهة فعّلت include_amounts صراحة.
 */
final class IntegrationEvents
{
    public const SCHEMA = 1;

    /** ما يُشترك فيه من الواجهة. ping للاختبار وحده: يُرسل للوجهة المختارة فقط */
    public const SUBSCRIBABLE = ['quote_request.created', 'quote_request.converted', 'invoice.approved'];

    public const ALLOWED = [...self::SUBSCRIBABLE, 'ping'];

    public const FIELDS = [
        // طلب تسعير أو حجز وصل من البوت. topics مفاتيح التوجيه (umrah وflights…)، وoffer_title عنوان العرض العام
        'quote_request.created' => ['request_number', 'type', 'status', 'offer_id', 'offer_title', 'topics', 'created_at'],
        // أُنشئت فاتورة عرض من الطلب (الحالة won). ليس اعتماداً محاسبياً: ذلك invoice.approved
        'quote_request.converted' => ['request_number', 'invoice_number', 'offer_id', 'created_at'],
        // اعتُمدت فاتورة محاسبياً. إعادة الاعتماد بعد «فتح للتعديل» حدث جديد بمعرّف جديد
        'invoice.approved' => ['invoice_number', 'invoice_date', 'trip_date', 'offer_id', 'client_code', 'approved_at', 'total_sell_jod'],
        'ping' => ['message', 'sent_at'],
    ];

    /** حقول لا تخرج إلا مع include_amounts=true للوجهة */
    public const AMOUNT_FIELDS = ['total_sell_jod'];

    public const LABELS = [
        'quote_request.created' => 'طلب تسعير أو حجز جديد',
        'quote_request.converted' => 'فاتورة عرض أُنشئت من طلب',
        'invoice.approved' => 'اعتماد فاتورة',
        'ping' => 'اختبار الاتصال',
    ];

    /**
     * الحقول التي تخرج فعلاً لوجهة بعينها.
     *
     * @return list<string>
     */
    public static function fieldsFor(string $event, bool $includeAmounts): array
    {
        $fields = self::FIELDS[$event] ?? [];

        return array_values($includeAmounts ? $fields : array_diff($fields, self::AMOUNT_FIELDS));
    }

    /** الحمولة البيضاء: ما عدا الحقول المعلنة يسقط */
    public static function whitelist(string $event, array $data, bool $includeAmounts): array
    {
        return array_intersect_key($data, array_flip(self::fieldsFor($event, $includeAmounts)));
    }

    /** الغلاف الموحّد: النوع والمعرّف الثابت عبر المحاولات (webhook-id) ووقت الحدث والإصدار والبيانات */
    public static function envelope(string $event, string $key, array $data, ?\DateTimeInterface $at = null): array
    {
        return [
            'type' => $event,
            'id' => $key,
            'timestamp' => ($at ?? now())->format(DATE_ATOM),
            'schema' => self::SCHEMA,
            'data' => (object) $data,
        ];
    }

    /** للواجهة: كل حدث بتسميته وحقوله كما ستخرج حرفياً */
    public static function catalog(): array
    {
        return array_map(fn (string $event) => [
            'key' => $event,
            'label' => self::LABELS[$event],
            'fields' => self::FIELDS[$event],
            'amount_fields' => array_values(array_intersect(self::FIELDS[$event], self::AMOUNT_FIELDS)),
        ], self::SUBSCRIBABLE);
    }
}
