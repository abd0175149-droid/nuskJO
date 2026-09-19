<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaCustomerNote extends Model
{
    public $timestamps = false;
    protected $fillable = ['conversation_id', 'client_id', 'note', 'source', 'created_by', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
