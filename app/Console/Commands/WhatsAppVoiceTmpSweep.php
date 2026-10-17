<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\MediaConverter;
use App\Services\WhatsApp\WaLog;
use Illuminate\Console\Command;

/**
 * كنس ساعي لمجلد التحويل الصوتي (E34.10): ما بقي في storage/app/tmp/voice أقدم من ساعة (عملية قُتلت قبل finally).
 * يطبع العدد وحده، بلا أسماء ملفات.
 */
class WhatsAppVoiceTmpSweep extends Command
{
    protected $signature = 'whatsapp:voice-tmp-sweep';

    protected $description = 'حذف ملفات التحويل الصوتي المؤقتة الأقدم من ساعة';

    private const GRACE_SECONDS = 3600;

    public function handle(): int
    {
        $n = 0;
        foreach (glob(MediaConverter::tmpDir().'/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - self::GRACE_SECONDS && @unlink($file)) {
                $n++;
            }
        }
        if ($n > 0) {
            WaLog::info('wa.voice.tmp_sweep', ['count' => $n]);
        }
        $this->line((string) $n);

        return self::SUCCESS;
    }
}
