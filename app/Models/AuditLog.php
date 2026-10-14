<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'user_id', 'user_name', 'action', 'entity_type',
        'entity_id', 'entity_label', 'changes', 'ip_address', 'request_id', 'created_at',
    ];
    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    private static ?bool $requestIdColumn = null;

    private static function hasRequestIdColumn(): bool
    {
        return self::$requestIdColumn ??= \Illuminate\Support\Facades\Schema::hasColumn('audit_logs', 'request_id');
    }

    /**
     * Log an audit event
     */
    public static function log(string $action, string $entityType, int $entityId, ?string $label = null, ?array $changes = null): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'user_name' => auth()->user()?->name,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_label' => $label,
            'changes' => $changes,
            'ip_address' => request()->ip(),
            // يربط السطر بسجلات الطلب نفسه (عمود من هجرة 2026_10_14_000006)
            'request_id' => self::hasRequestIdColumn() ? \Illuminate\Support\Facades\Context::get('request_id') : null,
            'created_at' => now(),
        ]);
    }
}
