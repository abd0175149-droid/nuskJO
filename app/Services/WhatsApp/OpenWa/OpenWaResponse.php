<?php

namespace App\Services\WhatsApp\OpenWa;

use Illuminate\Http\Client\Response;

/** نتيجة نداء واحد للبوابة: ناجح بجسمه، أو مرفوض/فاشل بخطأ مصنَّف */
final class OpenWaResponse
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?Response $response,
        public readonly ?OpenWaError $error,
    ) {}

    public static function ok(Response $res): self
    {
        return new self(true, $res, null);
    }

    public static function rejected(Response $res, OpenWaError $error): self
    {
        return new self(false, $res, $error);
    }

    public static function failed(OpenWaError $error): self
    {
        return new self(false, null, $error);
    }

    public function status(): ?int
    {
        return $this->response?->status();
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->response === null) {
            return $default;
        }
        $json = $this->response->json();

        return $key === null ? ($json ?? $default) : data_get($json, $key, $default);
    }

    public function body(): string
    {
        return (string) $this->response?->body();
    }

    public function header(string $name): ?string
    {
        $v = $this->response?->header($name);

        return $v === '' ? null : $v;
    }
}
