<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * كتم العميل لرسائل النظام: كلها (group وtype_key فارغان)، أو مجموعة، أو نوع. muted_until فارغ = دائم.
 * المصدر bot (طلبه العميل من البوت، manage_my_notifications) أو staff (من لوحة العميل).
 */
class WaNotificationPref extends Model
{
    protected $fillable = ['client_id', 'group', 'type_key', 'muted_until', 'source', 'created_by'];

    protected $casts = ['muted_until' => 'datetime'];
}
