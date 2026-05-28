<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLocation extends Model
{
    protected $fillable = [
        'name', 'type', 'ip_range', 'latitude', 'longitude',
        'radius_meters', 'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query) { return $query->where('is_active', true); }
    public function scopeIpType($query) { return $query->where('type', 'ip'); }
    public function scopeGeoType($query) { return $query->where('type', 'geo'); }
}
