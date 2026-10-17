<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * حدث حساب من ميتا (E22، AccountEventHandler): account_update وaccount_alerts وsecurity وأحداث الجودة والاسم
 * والقدرات والمراجعة. صف واحد لكل بصمة (إعادات ميتا لا تتكرر)، والحمولة الخام لأن الحقول تتغير بين الإصدارات.
 * لا بيانات عملاء: حقول الحساب هذه بلا wa_id ولا نصوص. الحمولة لا تُعرض إلا لصف واحد لصاحب whatsapp.settings.
 */
class WaAccountEvent extends Model
{
    use MassPrunable;

    public const SEVERITIES = ['critical', 'warn', 'info'];

    public const EFFECTS = ['none', 'alert', 'poll', 'limited', 'blocked', 'unblock_suggested', 'ignored_foreign'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'handled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** المعلوماتي بعد 180 يوماً؛ الحرج والتحذير يبقيان (E13، قائمة retention.prunable_models) */
    public function prunable(): Builder
    {
        return static::where('severity', 'info')->where('created_at', '<', now()->subDays((int) config('whatsapp.health.info_retention_days', 180)));
    }
}
