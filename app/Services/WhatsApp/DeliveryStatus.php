<?php

namespace App\Services\WhatsApp;

/**
 * رتب حالات التسليم والانتقالات المسموحة (E21). صنف بلا حالة ولا قاعدة: StatusApplier يبني منه شرط التحديث الذري
 * (WHERE status IS NULL OR status IN allowedFrom)، فالأعلى رتبة يفوز أياً كان ترتيب وصول إشعارات ميتا.
 *
 * - queued وunknown (ومعهما unconfirmed القديمة التي وحّدتها E19 في unknown) رتبتها 0: ما زال الصف ينتظر حسمه.
 * - failed خارج الرتب: لا تُكتب إلا فوق فراغ أو ما رتبته دون delivered. فـ failed المتأخرة فوق delivered أو read
 *   تبقى في التاريخ وحده (قاعدة دفاعية، لا نص صريح من ميتا).
 * - الدليل الإيجابي يتقدّم: delivered أو read أو played بعد failed (عميل على أكثر من جهاز) تصبح الحالة، ويبقى
 *   failed في التاريخ، فتعرض الفقاعة تلميح «فشل التسليم على أحد الأجهزة».
 * - blocked (حجب السياسة، E15) لا تصلها إشعارات ميتا ولا تُكتب فوقها.
 */
final class DeliveryStatus
{
    public const RANK = ['queued' => 0, 'unknown' => 0, 'unconfirmed' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'played' => 4];

    public const FAILED = 'failed';

    /** حالات ثبت فيها وصول الرسالة: تتقدّم على failed سابقة */
    public const POSITIVE = ['delivered', 'read', 'played'];

    /** القيم التي نعرفها: الرتب ومعها failed. غيرها (deleted مثلاً) لا يُكتب أبداً */
    public static function isKnown(string $status): bool
    {
        return isset(self::RANK[$status]) || $status === self::FAILED;
    }

    /**
     * الحالات الحالية التي يجوز أن تكتب فوقها $new. الفراغ (status IS NULL) يُعالج في الاستعلام نفسه.
     * الرتبة 0 لا تأتي من ميتا: لا تكتب فوق شيء.
     *
     * @return list<string>
     */
    public static function allowedFrom(string $new): array
    {
        if ($new === self::FAILED) {
            return array_keys(array_filter(self::RANK, fn (int $r) => $r <= self::RANK['sent']));
        }
        $rank = self::RANK[$new] ?? 0;
        if ($rank === 0) {
            return [];
        }
        $lower = array_keys(array_filter(self::RANK, fn (int $r) => $r < $rank));

        return in_array($new, self::POSITIVE, true) ? [...$lower, self::FAILED] : $lower;
    }

    /** هل تكتب $new فوق $current؟ (الفراغ null) — للاختبار الشامل وللمسار غير الذري */
    public static function advances(?string $current, string $new): bool
    {
        return self::isKnown($new) && ($current === null || in_array($current, self::allowedFrom($new), true));
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'queued' => 'قيد الإرسال',
            'sent' => 'أُرسلت',
            'delivered' => 'سُلّمت',
            'read' => 'قُرئت',
            'played' => 'شُغّلت',
            self::FAILED => 'فشلت',
            'unknown', 'unconfirmed' => 'غير مؤكدة',
            default => $status,
        };
    }
}
