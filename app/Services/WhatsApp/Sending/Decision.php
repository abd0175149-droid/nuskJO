<?php

namespace App\Services\WhatsApp\Sending;

use Carbon\CarbonInterface;

/** قرار SendGovernor لصف واحد: allow، أو defer حتى وقت بسبب، أو expire، أو deny بسبب */
final class Decision
{
    private function __construct(
        public readonly string $action,
        public readonly ?CarbonInterface $until = null,
        public readonly ?string $reason = null,
        public readonly ?string $code = null,
    ) {}

    public static function allow(): self
    {
        return new self('allow');
    }

    public static function defer(CarbonInterface $until, string $reason, string $code): self
    {
        return new self('defer', $until, $reason, $code);
    }

    public static function expire(): self
    {
        return new self('expire', null, 'انتهت صلاحية الرسالة قبل أن يحين إرسالها', 'expired');
    }

    public static function deny(string $reason, string $code): self
    {
        return new self('deny', null, $reason, $code);
    }

    public function allowed(): bool
    {
        return $this->action === 'allow';
    }
}
