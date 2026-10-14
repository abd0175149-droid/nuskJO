<?php

namespace App\Services\WhatsApp\Outbound;

/**
 * قرار سياسة المحتوى الصادر (E15.9). reasons: ما يحجب رسالة البوت والنظام عند الفرض. warnings: ما يُنبَّه إليه الموظف
 * ويتجاوزه بتأكيد. الأسباب رموز قصيرة (card, iban, passport, national_id, tool_leak, internal_field, raw_json, trace,
 * opted_out, error) تصير error_code=POLICY_<REASON>.
 */
final class Decision
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $reasons = [],
        public readonly array $warnings = [],
    ) {}

    public function blocks(): bool
    {
        return $this->reasons !== [];
    }

    public function errorCode(): string
    {
        return 'POLICY_'.strtoupper($this->reasons[0] ?? 'error');
    }

    /** للتخزين في payload._policy والسجل */
    public function toArray(): array
    {
        return array_filter(['reasons' => $this->reasons, 'warnings' => $this->warnings]);
    }
}
