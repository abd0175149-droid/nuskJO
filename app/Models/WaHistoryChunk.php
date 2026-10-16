<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * دفعة من سجل التعايش السابق (حقل history، C012): تصل مرة واحدة بعد الربط ولا تتكرر. تُحفظ مشفّرة بانتظار قرار
 * المالك (الاستيراد أو الإهمال) مدة whatsapp.coexistence.history_hold_days ثم تُمحى. لا تُعرض ولا تُسلسل:
 * HistoryHold والأمر whatsapp:coexistence-history ومسار المحو وحدها.
 */
class WaHistoryChunk extends Model
{
    public const HELD = 'held';

    public const IMPORTED = 'imported';

    public const DISCARDED = 'discarded';

    public const EXPIRED = 'expired';

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'received_at' => 'datetime',
            'purge_after' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
