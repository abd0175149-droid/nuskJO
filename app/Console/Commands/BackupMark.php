<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use Illuminate\Console\Command;

/**
 * يسجّل ما حدث للنسخة خارج الحاوية (يستدعيه scripts/backup/collect.sh وdrill.sh):
 *
 *   php artisan backup:mark 20261014T023000Z-scheduled --collected --archive=nuskjo-....tar.gz.age --bytes=123 --sha256=...
 *   php artisan backup:mark 20261014T023000Z-scheduled --pulled
 *   php artisan backup:mark 20261014T023000Z-scheduled --drill=ok --summary='{"integrity":"ok"}'
 *
 * غياب الصف ليس خطأً: النسخة قد تسبق الجدول أو تسبق استعادة؛ مصدر الحقيقة manifest.json.
 */
class BackupMark extends Command
{
    protected $signature = 'backup:mark
        {snapshot : اسم مجلد النسخة}
        {--collected : سحبها المجمّع على المضيف مشفّرة}
        {--pulled : نُقلت إلى جهاز المالك}
        {--drill= : نتيجة تمرين الاستعادة ok|failed}
        {--archive= : اسم الأرشيف المشفّر}
        {--bytes= : حجم الأرشيف}
        {--sha256= : بصمة الأرشيف}
        {--summary= : ملخص JSON للتمرين (أعداد وحالات، بلا بيانات شخصية)}';

    protected $description = 'تسجيل سحب نسخة احتياطية أو نقلها أو نتيجة تمرين استعادتها';

    public function handle(): int
    {
        $snapshot = (string) $this->argument('snapshot');
        if (!preg_match('/^\d{8}T\d{6}Z-[A-Za-z0-9_.-]{1,48}$/', $snapshot)) {
            $this->error('اسم نسخة غير صالح');

            return self::FAILURE;
        }

        $data = [];

        if ($this->option('collected')) {
            $archive = (string) $this->option('archive');
            $bytes = $this->option('bytes');
            $sha = strtolower((string) $this->option('sha256'));

            if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $archive) || !ctype_digit((string) $bytes) || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
                $this->error('--collected يتطلب --archive و--bytes و--sha256 صالحة');

                return self::FAILURE;
            }

            $data += [
                'collected_at' => now(),
                'archive_name' => $archive,
                'archive_bytes' => (int) $bytes,
                'archive_sha256' => $sha,
            ];
        }

        if ($this->option('pulled')) {
            $data['pulled_at'] = now();
        }

        if ($this->option('drill') !== null) {
            $result = (string) $this->option('drill');
            if (!in_array($result, ['ok', 'failed'], true)) {
                $this->error('--drill يقبل ok أو failed');

                return self::FAILURE;
            }

            $summary = json_decode((string) ($this->option('summary') ?? '{}'), true);
            if (!is_array($summary)) {
                $this->error('--summary ليس JSON صالحاً');

                return self::FAILURE;
            }

            $data['drill_summary'] = ['result' => $result, 'at' => now()->toIso8601String()] + $summary;
        }

        if (!$data) {
            $this->error('حدّد --collected أو --pulled أو --drill');

            return self::FAILURE;
        }

        $run = BackupRun::where('snapshot_name', $snapshot)->latest('id')->first();
        if (!$run) {
            $this->warn("لا يوجد سجل للنسخة {$snapshot} في هذه القاعدة؛ تُجوهل التسجيل");

            return self::SUCCESS;
        }

        $run->update($data);
        $this->info("✅ {$snapshot}");

        return self::SUCCESS;
    }
}
