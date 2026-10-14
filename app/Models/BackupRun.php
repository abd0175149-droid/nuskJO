<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    public const REASONS = ['scheduled', 'pre-migrate', 'pre-reset', 'manual', 'pre-restore', 'drill'];

    protected $fillable = [
        'reason', 'status', 'snapshot_name', 'db_bytes', 'media_bytes', 'manifest_sha256',
        'integrity', 'row_counts', 'migrations_count', 'error', 'triggered_by', 'collected_at',
        'archive_name', 'archive_bytes', 'archive_sha256', 'pulled_at', 'drill_summary',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'row_counts' => 'array',
        'drill_summary' => 'array',
        'collected_at' => 'datetime',
        'pulled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
