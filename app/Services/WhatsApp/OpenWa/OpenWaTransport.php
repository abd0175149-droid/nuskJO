<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Models\WaChannel;
use App\Services\WhatsApp\Transport\ChannelTransport;

/** الناقل الوحيد في الإنتاج: OpenWaClient بمفتاح الرقم داخل جلسته */
final class OpenWaTransport implements ChannelTransport
{
    public function call(WaChannel $channel, string $method, string $path, ?array $body = null, string $timeout = 'default', array $headers = [], array $query = []): OpenWaResponse
    {
        return OpenWaClient::for($channel)->session($method, $path, $body, $timeout, $headers, $query);
    }

    public function name(): string
    {
        return 'openwa';
    }
}
