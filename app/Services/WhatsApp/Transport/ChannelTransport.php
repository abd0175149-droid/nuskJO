<?php

namespace App\Services\WhatsApp\Transport;

use App\Models\WaChannel;
use App\Services\WhatsApp\OpenWa\OpenWaResponse;

/**
 * الناقل إلى جلسة رقم على البوابة (2026-11-06): نداء واحد داخل /sessions/{id}، بمفتاح الرقم المحصور.
 * الإنتاج: OpenWaTransport وحده. LogTransport بلا شبكة للاختبار والتطوير المحلي، ويُرفض في production (AppServiceProvider).
 * الأقفال كلها فوق الناقل في WhatsAppChannel، فلا يتجاوزها أي ناقل. لا يرمي: الفشل OpenWaResponse بخطأ مصنَّف.
 */
interface ChannelTransport
{
    /**
     * @param  string  $path  داخل الجلسة، مثل /messages/send-text
     * @param  string  $timeout  default | send | start
     */
    public function call(WaChannel $channel, string $method, string $path, ?array $body = null, string $timeout = 'default', array $headers = [], array $query = []): OpenWaResponse;

    /** openwa أو log */
    public function name(): string;
}
