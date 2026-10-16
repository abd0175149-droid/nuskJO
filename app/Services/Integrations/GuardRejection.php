<?php

namespace App\Services\Integrations;

/** رفض حارس الروابط الصادرة (E40): رمز ثابت للسجل والاختبار، ورسالة عربية لنموذج الوجهة */
final class GuardRejection
{
    public const MESSAGES = [
        'invalid' => 'رابط غير صالح.',
        'too_long' => 'الرابط أطول من 512 حرفاً.',
        'not_https' => 'الرابط يجب أن يبدأ بـ https://',
        'userinfo' => 'الرابط لا يقبل اسم مستخدم أو كلمة مرور.',
        'port' => 'المنفذ غير مسموح: 443 وحده ما لم يُسمح بغيره من البيئة.',
        'ip_literal' => 'اكتب اسم المضيف، لا عنوان IP.',
        'internal_host' => 'مضيف داخلي (localhost أو .local أو .internal) غير مسموح.',
        'self_host' => 'الرابط يعود إلى نظامنا نفسه.',
        'dns_failed' => 'تعذّر تحليل اسم المضيف.',
        'private_address' => 'العنوان يحل إلى شبكة خاصة أو محجوزة.',
    ];

    public function __construct(public readonly string $code) {}

    public function message(): string
    {
        return self::MESSAGES[$this->code] ?? self::MESSAGES['invalid'];
    }
}
