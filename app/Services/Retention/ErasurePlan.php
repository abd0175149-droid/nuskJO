<?php

namespace App\Services\Retention;

/** ما سيُحذف لمحادثة (E13): معرّفات وأعداد فقط. البصمة بلا عدد الرسائل (قد تصل رسائل بعد المعاينة) */
final class ErasurePlan
{
    /**
     * @param  list<int>  $conversationIds  المحادثة والمدموجة فيها
     * @param  array<string, int>  $counts
     * @param  list<string>  $mediaIds  وسائط حديثة يُطلب حذفها من ميتا (forget وحده)
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $conversationIds,
        public readonly string $mode,
        public readonly ?string $canonicalPhone,
        public readonly array $counts,
        public readonly array $mediaIds,
    ) {}

    public function fingerprint(): string
    {
        $c = $this->counts;
        unset($c['messages']);
        ksort($c);

        return substr(hash('sha256', $this->conversationId.'|'.$this->mode.'|'.json_encode($c)), 0, 16);
    }
}
