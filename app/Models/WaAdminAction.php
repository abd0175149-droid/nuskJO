<?php

namespace App\Models;

use App\Services\WhatsApp\AdminTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجلّ أدوات المساعد الإداري: ما نُفّذ، وما رُفض، وما عُرضت معاينته */
class WaAdminAction extends Model
{
    use MassPrunable;

    /**
     * الكتابات المنفّذة (AdminTools::writes() بحالة done) تبقى سنتين، وكل ما عداها (قراءات ومعاينات ورفض وأخطاء)
     * 180 يوماً (E13). الحالة done وحدها لا تميّز: القراءات تُسجَّل done أيضاً.
     */
    public function prunable(): Builder
    {
        $write = function ($q) {
            $q->where('status', 'done')->where(function ($w) {
                foreach (AdminTools::writes() as $tool => $actions) {
                    $w->orWhere(fn ($x) => $x->where('tool', $tool)->whereIn('action', $actions));
                }
            });
        };

        return static::where('created_at', '<', now()->subDays((int) config('retention.admin_actions_write_days', 730)))
            ->orWhere(fn ($q) => $q->where('created_at', '<', now()->subDays((int) config('retention.admin_actions_read_days', 180)))->whereNot($write));
    }

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
