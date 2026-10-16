<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\WhatsApp\Search\FtsSchema;
use App\Services\WhatsApp\TextNormalizer;
use App\Services\WhatsApp\WaLog;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Support\AuditActor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صحة فهرس البحث في الرسائل (E30.2، يومياً مع --repair): الجدول والـ triggers الثلاثة، والحذف النهائي ('secure-delete')
 * بحسب ما يصح في هذه النسخة من SQLite، وintegrity-check مقابل wa_messages، وعدد المفهرس مقابل الرسائل، وعيّنة
 * تطبيع، وإصدار قواعد التطبيع. هجرة لاحقة تعيد بناء wa_messages تُسقط الـ triggers بصمت، فهذا ما يكشفها.
 * يخرج بـ 1 عند أي خلل. مع --repair يصلح ويدقّق ويُشعر المدراء مرة في اليوم. أعداد فقط، بلا نص.
 */
class WhatsAppFtsCheck extends Command
{
    protected $signature = 'whatsapp:fts-check {--repair : يصلح ما ينقص ويعيد بناء الفهرس} {--json : يطبع JSON}';

    protected $description = 'فحص فهرس البحث في الرسائل (FTS5) وإصلاحه';

    private const SAMPLE = 200;

    public function handle(): int
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->info('لا فهرس خارج SQLite: البحث بـ LIKE');

            return self::SUCCESS;
        }

        $report = $this->inspect();
        $repaired = false;
        if ($report['problems'] !== [] && $this->option('repair')) {
            $actions = AuditActor::as('system', 'artisan:whatsapp:fts-check', fn () => $this->repair($report));
            $repaired = true;
            $report = $this->inspect() + ['actions' => $actions];
        }

        FtsSchema::recordCheck($report['problems'] !== [] ? 'failed' : ($repaired ? 'repaired' : $report['state']), $repaired,
            $report['state'] === 'unsupported' ? null : $report['secure_delete']['on']);
        $this->output($report);

        return $report['problems'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function inspect(): array
    {
        $support = FtsSchema::support();
        $problems = [];
        $norm = ['version' => FtsSchema::normVersion(), 'expected' => TextNormalizer::VERSION, 'drift' => FtsSchema::sampleDrift(self::SAMPLE), 'sample' => self::SAMPLE];
        if ($norm['version'] !== null && $norm['version'] !== $norm['expected']) {
            $problems[] = 'norm_version';
        }
        if ($norm['drift'] > 0) {
            $problems[] = 'norm_drift';
        }

        if (!$support['trigram']) {
            // triggers من صورة سابقة تدعم الفهرس: تُفشل كل كتابة في wa_messages هنا
            if (count(FtsSchema::missingTriggers()) < count(FtsSchema::TRIGGERS)) {
                $problems[] = 'orphan_triggers';
            }

            return ['state' => 'unsupported', 'sqlite' => $support['version'], 'support' => $support, 'available' => false,
                'secure_delete' => ['on' => false, 'works' => false], 'norm' => $norm, 'problems' => $problems];
        }

        $table = Schema::hasTable(FtsSchema::TABLE);
        $missing = FtsSchema::missingTriggers();
        if (!$table) {
            $problems[] = 'missing_table';
        }
        foreach ($missing as $name) {
            $problems[] = "missing_trigger:{$name}";
        }
        $secure = ['on' => $table && FtsSchema::secureDeleteOn(), 'works' => $support['secure_delete']];
        if ($table && $secure['on'] !== $secure['works']) {
            $problems[] = 'secure_delete';
        }
        $integrity = $table ? FtsSchema::integrityOk() : false;
        if ($table && !$integrity) {
            $problems[] = 'integrity';
        }
        $counts = FtsSchema::counts();
        if ($table && $counts['indexed'] !== $counts['messages']) {
            $problems[] = 'count_mismatch';
        }

        return ['state' => 'ok', 'sqlite' => $support['version'], 'support' => $support, 'available' => $table && $missing === [],
            'triggers' => ['missing' => $missing, 'present' => count(FtsSchema::TRIGGERS) - count($missing)], 'secure_delete' => $secure,
            'integrity' => $integrity, 'counts' => $counts, 'norm' => $norm, 'problems' => $problems];
    }

    /** @return list<string> ما فُعل */
    private function repair(array $report): array
    {
        $actions = [];
        if (in_array('norm_version', $report['problems'], true)) {
            $actions[] = 'renormalized:'.FtsSchema::renormalize();
        } elseif (in_array('norm_drift', $report['problems'], true)) {
            $actions[] = 'drift_fixed:'.FtsSchema::sampleDrift(self::SAMPLE, true);
        }
        if ($report['state'] !== 'unsupported') {
            array_push($actions, ...FtsSchema::repair());
        } elseif (in_array('orphan_triggers', $report['problems'], true)) {
            FtsSchema::dropTriggers();
            $actions[] = 'triggers_dropped';
        }

        AuditLog::safe(AuditAction::WaSearchIndexRepair, 'system', null, null, ['reason' => $report['problems'], 'actions' => $actions]);
        WaLog::warning('wa.fts.repaired', ['reason' => implode(',', $report['problems']), 'count' => count($actions)]);
        if (Cache::add('wa:fts:notified:'.now()->toDateString(), 1, now()->endOfDay())) {
            WhatsAppInbox::notifyAdmins('🔎 فهرس البحث أُصلح', 'أُعيد بناء فهرس البحث في الرسائل تلقائياً بعد خلل. راجع آخر هجرة طُبّقت.', '/whatsapp/settings#ops');
        }

        return $actions;
    }

    private function output(array $r): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->line("SQLite {$r['sqlite']} · FTS5: ".($r['support']['fts5'] ? 'نعم' : 'لا').' · trigram: '.($r['support']['trigram'] ? 'نعم' : 'لا'));
        if ($r['state'] === 'unsupported') {
            $this->warn('available: لا — SQLite بلا FTS5/trigram، والبحث يعمل بـ LIKE (أبطأ)');
        } else {
            $this->line('available: '.($r['available'] ? 'نعم' : 'لا').' · triggers: '.$r['triggers']['present'].'/'.count(FtsSchema::TRIGGERS)
                .($r['triggers']['missing'] ? ' (ناقص: '.implode('، ', $r['triggers']['missing']).')' : ''));
            $this->line('secure_delete: '.($r['secure_delete']['on'] ? 'on' : 'off')
                .($r['secure_delete']['works'] ? '' : ' — معطوب في هذه النسخة من SQLite (يُصلح في 3.46.1)، فالمحذوف يُمحى بإعادة كتابة الفهرس بعد كل حذف جماعي'));
            $this->line('integrity: '.($r['integrity'] ? 'ok' : 'فشل').' · المفهرس/الرسائل: '.$r['counts']['indexed'].'/'.$r['counts']['messages']);
        }
        $this->line('التطبيع: الإصدار '.($r['norm']['version'] ?? '—')."/{$r['norm']['expected']} · مختلف في العيّنة: {$r['norm']['drift']}/{$r['norm']['sample']}");
        if (!empty($r['actions'])) {
            $this->info('أُصلح: '.implode('، ', $r['actions']));
        }
        foreach ($r['problems'] as $p) {
            $this->error("❌ {$p}");
        }
        if ($r['problems'] === []) {
            $this->info('✅ فهرس البحث سليم');
        } elseif (!$this->option('repair')) {
            $this->line('للإصلاح: php artisan whatsapp:fts-check --repair');
        }
    }
}
