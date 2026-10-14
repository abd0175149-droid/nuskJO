<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /** مفاتيح يكتبها النظام وحده: لا تُعرض في صفحة الإعدادات ولا تُكتب منها (القائمة الكاملة في E03) */
    public const INTERNAL_KEYS = ['restore_review_pending'];

    public $timestamps = false;
    protected $fillable = ['key', 'value', 'group_name', 'type', 'description'];

    public static function get(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_at' => now()]);
    }
}
