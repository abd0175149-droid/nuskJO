<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaBotUsage extends Model
{
    protected $table = 'wa_bot_usage';
    public $timestamps = false;
    protected $fillable = [
        'conversation_id', 'source', 'model', 'calls',
        'prompt_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'created_at',
    ];
    protected $casts = ['created_at' => 'datetime'];
}
