<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر في سجل جلسة رقم (qr، الربط، الانقطاع، العودة، إعادة التشغيل، علامة الحظر)، يُعرض في صفحة الأرقام */
class WaChannelEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['channel_id', 'event', 'detail', 'created_at'];

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];

    public function channel(): BelongsTo { return $this->belongsTo(WaChannel::class, 'channel_id'); }
}
