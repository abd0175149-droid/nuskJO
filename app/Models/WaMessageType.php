<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ما عدّله المدير على نوع رسالة نظام (System\Catalog هو المصدر): التفعيل والوضع والمرحلة والقالب والتوقيت والرقم.
 * الحقل الفارغ = قيمة الكود.
 */
class WaMessageType extends Model
{
    protected $fillable = [
        'key', 'enabled', 'mode', 'stage', 'tests_count', 'last_test_at', 'channel_id', 'template', 'timing',
        'expires_after_minutes', 'priority', 'cancel_on_reply', 'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean', 'cancel_on_reply' => 'boolean', 'timing' => 'array', 'tests_count' => 'integer',
        'last_test_at' => 'datetime', 'expires_after_minutes' => 'integer', 'priority' => 'integer',
    ];

    public const MODES = ['auto', 'approve', 'off'];

    public const STAGES = ['test', 'live'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\WhatsApp\System\Catalog::flush());
    }
}
