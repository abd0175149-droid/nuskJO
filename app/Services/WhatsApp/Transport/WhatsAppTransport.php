<?php

namespace App\Services\WhatsApp\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * الناقل إلى ميتا (E23): واجهة صغيرة بتوقيع GraphClient::request نفسه، بلا نظام إضافات ولا محركات بديلة.
 * الإنتاج: CloudApiTransport وحده (Cloud API الرسمية عبر GraphClient). LogTransport للاختبار والتطوير المحلي
 * فقط، ويُرفض في production مهما كان الإعداد (AppServiceProvider). الأقفال كلها فوق الناقل في WhatsAppChannel::dispatch.
 */
interface WhatsAppTransport
{
    /**
     * @param  list<string>  $segments
     *
     * @throws \InvalidArgumentException إعداد غير صالح (قبل أي اتصال)
     * @throws ConnectionException فشل الاتصال أو المهلة
     */
    public function request(string $method, array $segments, array $data = [], #[\SensitiveParameter] ?string $token = null, int $timeout = 25, int $connectTimeout = 10): Response;

    /** cloud أو log */
    public function name(): string;
}
