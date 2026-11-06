<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * حد إرسال استباقي لرقم ونوع رسالة (Sending\LimitResolver). channel_id وtype_key فارغان = «كل الأرقام» و«كل الأنواع».
 * كل سقف فارغ = بلا سقف. النافذة بساعات عمّان [window_from, window_to)، وweekdays أرقام ISO (1 الاثنين … 7 الأحد).
 * الردود (بوت وموظف) لا تخضع لهذه الحدود أبداً: مسار سريع في WhatsAppChannel.
 */
class WaSendLimit extends Model
{
    protected $fillable = [
        'channel_id', 'type_key', 'per_minute', 'per_hour', 'per_day', 'cold_per_day', 'per_recipient_day',
        'min_gap_sec', 'jitter_pct', 'spread', 'window_from', 'window_to', 'weekdays', 'updated_by',
    ];

    protected $casts = [
        'per_minute' => 'integer', 'per_hour' => 'integer', 'per_day' => 'integer', 'cold_per_day' => 'integer',
        'per_recipient_day' => 'integer', 'min_gap_sec' => 'integer', 'jitter_pct' => 'integer', 'spread' => 'boolean',
        'window_from' => 'integer', 'window_to' => 'integer', 'weekdays' => 'array',
    ];

    /** الحقول القابلة للدمج من الأعم إلى الأخص */
    public const FIELDS = ['per_minute', 'per_hour', 'per_day', 'cold_per_day', 'per_recipient_day', 'min_gap_sec', 'jitter_pct', 'spread', 'window_from', 'window_to', 'weekdays'];

    /** القيم الافتراضية المحافظة (صف كل الأرقام × كل الأنواع): رقم غير رسمي، والحظر خطر حقيقي */
    public const DEFAULTS = [
        'per_minute' => 4, 'per_hour' => 60, 'per_day' => 300, 'cold_per_day' => 30, 'per_recipient_day' => 3,
        'min_gap_sec' => 25, 'jitter_pct' => 30, 'spread' => true, 'window_from' => 9, 'window_to' => 21, 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
    ];
}
