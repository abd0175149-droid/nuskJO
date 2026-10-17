<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * فحص مخطط جداول الواتساب قبل هجرة وبعدها (E11.2): عدد الصفوف وmax(id) والفهارس المتوقعة وسلامة الملف
 * وقابلية phone للفراغ. يحفظ JSON في storage/app/reports للمقارنة بين التشغيلين، ويعيد 1 عند أي نقص.
 * لا يطبع رقماً ولا معرّفاً ولا نصاً: أعداداً وأسماء فهارس فقط.
 */
class WhatsAppVerifySchema extends Command
{
    protected $signature = 'whatsapp:verify-schema {--json : يطبع JSON بدل الجداول}';

    protected $description = 'فحص مخطط جداول الواتساب: الصفوف والفهارس والسلامة (قبل الهجرة وبعدها)';

    private const TABLES = ['wa_conversations', 'wa_messages', 'wa_optouts', 'quote_requests'];

    /** الفهرس => الهجرة التي تنشئه (لا يُطلب قبل تشغيلها) */
    private const EXPECTED = [
        'wa_conversations' => [
            'wa_conversations_phone_unique' => null,
            'wa_conversations_client_id_index' => null,
            'wa_conversations_user_id_index' => null,
            'wa_conversations_last_message_at_index' => null,
            'wa_conversations_needs_attention_index' => null,
            'wa_conversations_is_admin_chat_index' => '2026_10_14_000005_whatsapp_conversation_fence',
            'wa_conversations_last_inbound_at_index' => '2026_10_14_000007_whatsapp_reply_reliability',
            'wa_conversations_wa_user_id_unique' => '2026_10_14_000009_wa_identity_bsuid',
        ],
        'wa_messages' => [
            'wa_messages_wamid_unique' => null,
            'wa_messages_conversation_id_index' => null,
            'wa_messages_conversation_id_id_index' => null,
            'wa_messages_reply_to_wamid_index' => '2026_10_14_000012_wa_inbound_types_and_client_link',
            'wa_messages_send_key_unique' => '2026_10_14_000012_wa_inbound_types_and_client_link',
        ],
        'wa_optouts' => [
            'wa_optouts_phone_unique' => null,
            'wa_optouts_wa_user_id_unique' => '2026_10_14_000009_wa_identity_bsuid',
        ],
        'quote_requests' => [
            'quote_requests_request_number_unique' => null,
            'quote_requests_conversation_id_index' => null,
            'quote_requests_client_id_index' => null,
            'quote_requests_status_created_at_index' => null,
        ],
    ];

    private const NULLABLE_SINCE = '2026_10_14_000009_wa_identity_bsuid';

    /** فهرس البحث في الرسائل (E30): الجدول والـ triggers الثلاثة متوقعة بعد هجرته حيث تدعمها SQLite */
    private const FTS_SINCE = '2026_10_14_000030_wa_messages_fts';

    public function handle(): int
    {
        $report = $this->inspect();
        $path = $this->save($report);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['الجدول', 'الصفوف', 'max(id)', 'الفهارس', 'الناقص'], array_map(fn ($t, $r) => [
                $t, $r['rows'] ?? '—', $r['max_id'] ?? '—', count($r['indexes'] ?? []), implode('، ', $r['missing_indexes'] ?? []) ?: '—',
            ], array_keys($report['tables']), $report['tables']));
            $this->line('integrity_check: '.$report['integrity_check'].' · foreign_key_check: '.$report['foreign_key_violations']);
            foreach ($report['nullable'] as $column => $nullable) {
                $this->line("{$column} يقبل الفراغ: ".($nullable === null ? '—' : ($nullable ? 'نعم' : 'لا')));
            }
            $this->line('فهرس البحث: '.$report['search_index']['state']);
            $this->line('JSON: '.$path);
        }

        if ($report['gaps'] !== []) {
            foreach ($report['gaps'] as $gap) {
                $this->error('❌ '.$gap);
            }

            return self::FAILURE;
        }
        $this->option('json') || $this->info('✅ المخطط كما يُتوقع');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function inspect(): array
    {
        $ran = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        $gaps = [];
        $tables = [];

        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                $tables[$table] = ['exists' => false];
                $gaps[] = "الجدول {$table} غير موجود";

                continue;
            }
            $indexes = collect(Schema::getIndexes($table))->pluck('name')->reject(fn ($n) => $n === 'primary')->sort()->values()->all();
            $expected = array_keys(array_filter(self::EXPECTED[$table], fn ($since) => $since === null || in_array($since, $ran, true)));
            $missing = array_values(array_diff($expected, $indexes));
            foreach ($missing as $index) {
                $gaps[] = "فهرس ناقص: {$index}";
            }
            $tables[$table] = [
                'exists' => true,
                'rows' => DB::table($table)->count(),
                'max_id' => (int) DB::table($table)->max('id'),
                'indexes' => $indexes,
                'missing_indexes' => $missing,
            ];
        }

        $integrity = 'n/a';
        $fkViolations = 0;
        if (DB::getDriverName() === 'sqlite') {
            $rows = collect(DB::select('PRAGMA integrity_check'))->map(fn ($r) => (string) array_values((array) $r)[0]);
            $integrity = $rows->count() === 1 && $rows->first() === 'ok' ? 'ok' : 'errors:'.$rows->count();
            $fkViolations = count(DB::select('PRAGMA foreign_key_check'));
            if ($integrity !== 'ok') {
                $gaps[] = 'integrity_check لم يعطِ ok';
            }
            if ($fkViolations > 0) {
                $gaps[] = "foreign_key_check: {$fkViolations} مخالفة";
            }
        }

        $nullable = [];
        $columns = Schema::hasTable('wa_conversations') ? collect(Schema::getColumns('wa_conversations'))->keyBy('name') : collect();
        foreach (['phone', 'wa_phone'] as $column) {
            $nullable["wa_conversations.{$column}"] = $columns->has($column) ? (bool) $columns[$column]['nullable'] : null;
            if (in_array(self::NULLABLE_SINCE, $ran, true) && $nullable["wa_conversations.{$column}"] !== true) {
                $gaps[] = "wa_conversations.{$column} لا يقبل الفراغ بعد هجرة الهوية";
            }
        }

        $search = ['state' => 'n/a'];
        if (DB::getDriverName() === 'sqlite' && in_array(self::FTS_SINCE, $ran, true)) {
            if (!\App\Services\WhatsApp\Search\FtsSchema::supported()) {
                $search = ['state' => 'unsupported'];
            } else {
                $table = Schema::hasTable(\App\Services\WhatsApp\Search\FtsSchema::TABLE);
                $missing = \App\Services\WhatsApp\Search\FtsSchema::missingTriggers();
                $search = ['state' => $table && $missing === [] ? 'ok' : 'gaps', 'table' => $table, 'missing_triggers' => $missing];
                if (!$table) {
                    $gaps[] = 'جدول فهرس البحث wa_messages_fts غير موجود (whatsapp:fts-check --repair)';
                }
                foreach ($missing as $trigger) {
                    $gaps[] = "trigger ناقص: {$trigger} (whatsapp:fts-check --repair)";
                }
            }
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'driver' => DB::getDriverName(),
            'last_migration' => end($ran) ?: null,
            'tables' => $tables,
            'integrity_check' => $integrity,
            'foreign_key_violations' => $fkViolations,
            'nullable' => $nullable,
            'search_index' => $search,
            'gaps' => $gaps,
        ];
    }

    private function save(array $report): string
    {
        $dir = storage_path('app/reports');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/wa-schema-'.now()->format('Ymd-His').'.json';
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
