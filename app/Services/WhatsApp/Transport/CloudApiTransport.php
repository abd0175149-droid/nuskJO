<?php

namespace App\Services\WhatsApp\Transport;

use App\Services\WhatsApp\Graph\GraphClient;
use Illuminate\Http\Client\Response;

/**
 * الناقل الوحيد في الإنتاج: يفوّض حرفياً إلى GraphClient (E18)، فيبقى appsecret_proof ومنع التحويلات وترميز المقاطع
 * والعدّادات في مكان واحد. السائق الوحيد المسموح هو WhatsApp Cloud API الرسمية؛ يُمنع whatsapp-web.js وBaileys
 * وأي عميل غير رسمي (E05).
 */
final class CloudApiTransport implements WhatsAppTransport
{
    public function request(string $method, array $segments, array $data = [], #[\SensitiveParameter] ?string $token = null, int $timeout = 25, int $connectTimeout = 10): Response
    {
        return GraphClient::request($method, $segments, $data, $token, $timeout, $connectTimeout);
    }

    public function name(): string
    {
        return 'cloud';
    }
}
