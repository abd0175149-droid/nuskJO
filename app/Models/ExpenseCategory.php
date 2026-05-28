<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseCategory extends Model
{
    use SoftDeletes;
    protected $fillable = ['name', 'code', 'description', 'is_active', 'account_id'];
    protected $casts = ['is_active' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'category_id');
    }
}
