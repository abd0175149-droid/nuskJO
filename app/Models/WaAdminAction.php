<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجلّ أدوات المساعد الإداري: ما نُفّذ، وما رُفض، وما عُرضت معاينته */
class WaAdminAction extends Model
{
    protected $table = 'wa_admin_actions';
    public $timestamps = false;

    protected $fillable = [
        'admin_number_id', 'conversation_id', 'tool', 'action', 'status', 'summary', 'input', 'created_at',
    ];

    protected $casts = ['input' => 'array', 'created_at' => 'datetime'];

    public function adminNumber(): BelongsTo
    {
        return $this->belongsTo(WaAdminNumber::class, 'admin_number_id');
    }
}
