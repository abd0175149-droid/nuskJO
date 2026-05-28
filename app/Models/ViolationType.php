<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ViolationType extends Model
{
    use SoftDeletes;
    protected $fillable = ['name', 'code', 'default_cost_sar', 'description', 'is_active', 'account_id'];

    protected $casts = [
        'default_cost_sar' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function violations()
    {
        return $this->hasMany(Violation::class);
    }
}
