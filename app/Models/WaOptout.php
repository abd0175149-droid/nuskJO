<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaOptout extends Model
{
    public $timestamps = false;
    protected $fillable = ['phone', 'reason', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
