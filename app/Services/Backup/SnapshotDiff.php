<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ما في لقطة ما قبل الاستعادة ولم يعد في القاعدة الحية بعد وقت النسخة: للموظف كي يعيد إدخاله يدوياً.
 * أرقام مستندات ومعرّفات فقط، بلا أسماء ولا هواتف. لا يُعاد إدراج المستندات آلياً لأن روابطها
 * بعملاء ووكلاء قد لا تكون موجودة.
 */
class SnapshotDiff
{
    /** الجداول التي لها رقم مستند فريد يُعرض للموظف */
    public const DOCUMENT_NUMBERS = [
        'invoices' => 'invoice_number',
        'receipts' => 'receipt_number',
        'disbursements' => 'disbursement_number',
        'journal_entries' => 'entry_number',
        'quote_requests' => 'request_number',
    ];

    /** تُسترجع آلياً بـ backup:replay-messages */
    private const REPLAYED = ['wa_conversations', 'wa_messages'];

    /** @return array<string, array{missing:int, identifiers:list<string>}> */
    public function report(string $snapshotPath, string $since, int $limit = 50): array
    {
        $source = MessageReplayer::open($snapshotPath);
        $sinceUtc = Carbon::parse($since)->utc()->format('Y-m-d H:i:s');
        $tables = array_unique(array_merge(config('backup.core_tables', []), array_keys(self::DOCUMENT_NUMBERS)));
        $out = [];

        try {
            foreach ($tables as $table) {
                if (in_array($table, self::REPLAYED, true)
                    || !$source->getSchemaBuilder()->hasColumn($table, 'created_at')
                    || !DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $key = self::DOCUMENT_NUMBERS[$table] ?? 'id';
                $newer = $source->table($table)->where('created_at', '>', $sinceUtc)->orderBy('id')->get(['id', $key === 'id' ? 'id' : $key, 'created_at']);
                if ($newer->isEmpty()) {
                    continue;
                }

                $missing = $newer->filter(function ($row) use ($table, $key) {
                    $live = DB::table($table)->where($key, $row->{$key});
                    if ($key === 'id') {
                        // المعرّف قد يُعاد استعماله في الحية لصف آخر بعد الاستعادة
                        $live->where('created_at', $row->created_at);
                    }

                    return !$live->exists();
                });

                if ($missing->isNotEmpty()) {
                    $out[$table] = [
                        'missing' => $missing->count(),
                        'identifiers' => $missing->take($limit)->map(fn ($r) => $key === 'id' ? '#'.$r->id : (string) $r->{$key})->values()->all(),
                    ];
                }
            }
        } finally {
            DB::purge('backup_replay_source');
        }

        return $out;
    }
}
