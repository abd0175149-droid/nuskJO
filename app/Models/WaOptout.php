<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaOptout extends Model
{
    public $timestamps = false;
    // phone أو wa_user_id (عميل باسم مستخدم بلا رقم)
    protected $fillable = ['phone', 'wa_user_id', 'reason', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
