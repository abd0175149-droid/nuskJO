<?php

namespace Tests\Feature\WhatsApp;

/**
 * حسم «غير مؤكد» بـ biz_opaque (E19.7) نفسه عبر StatusApplier (E21، العلم delivery_status_v2): الاختبارات كلها
 * تُعاد على المسار الذري، فلا يتغير سلوك E19 بتفعيل العلم.
 */
class OpaqueStatusResolutionApplierTest extends OpaqueStatusResolutionTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['features.env.delivery_status_v2' => true]);
    }
}
