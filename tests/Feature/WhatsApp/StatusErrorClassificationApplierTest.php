<?php

namespace Tests\Feature\WhatsApp;

/**
 * الأخطاء غير المتزامنة (E18) نفسها عبر StatusApplier (E21، العلم delivery_status_v2): الأعمدة الأربعة، والسطر
 * بالمعرّفات، وإبلاغ المرسل مرة واحدة، والإيقاف عبر المفاعل نفسه. والعلم wa_event_bus مطفأ: الإبلاغ مباشر.
 */
class StatusErrorClassificationApplierTest extends StatusErrorClassificationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.delivery_status_v2' => true]);
    }
}
