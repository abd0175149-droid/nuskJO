<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حظر رسمي عند ميتا لجهة اتصال (E31). يكتبه BlockService وحده، بعد أن تقبل ميتا الحظر.
 * نص السبب هنا وحده: سجل التدقيق يحمل رمز السبب وطوله، لا نصاً حراً.
 *
 * meta_state: blocked (آخر ما نعرفه من ميتا) · unblocked · not_found (المزامنة لم تجده عند ميتا) ·
 * mismatch (وصلت رسالة من المحظور، فالحظر عند ميتا سقط أو فُكّ من خارج النظام).
 */
class WaBlock extends Model
{
    public const STATES = ['blocked', 'unblocked', 'not_found', 'mismatch'];

    public const VIA = ['dashboard', 'admin_number', 'meta_sync'];

    protected $fillable = [
        'conversation_id', 'phone', 'wa_user_id', 'subject_hash', 'reason_code', 'reason',
        'blocked_by', 'blocked_via', 'admin_number_id', 'blocked_at',
        'unblocked_by', 'unblocked_at', 'unblock_reason',
        'meta_state', 'meta_error_code', 'meta_checked_at', 'erased_at',
    ];

    protected $casts = [
        'blocked_at' => 'datetime',
        'unblocked_at' => 'datetime',
        'meta_checked_at' => 'datetime',
        'erased_at' => 'datetime',
    ];

    /**
     * الحظر القائم عندنا: لم يُفك. يشمل not_found وmismatch، فالقفل المحلي باقٍ حتى يقرر المدير
     * (المواصفة قصرته على blocked، فكانت المزامنة التالية لا ترى صفاً سقط عند ميتا).
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('unblocked_at');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'conversation_id');
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function unblocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unblocked_by');
    }

    public function adminNumber(): BelongsTo
    {
        return $this->belongsTo(WaAdminNumber::class, 'admin_number_id');
    }

    public function isActive(): bool
    {
        return $this->unblocked_at === null;
    }

    /** آخر 4 أرقام للعرض، أو null لمن لا رقم له (أو بعد «حذف بياناتي») */
    public function last4(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone) ?? '';

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }
}
