<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\WhatsApp\Search\FtsSchema;
use App\Services\WhatsApp\TextNormalizer;
use App\Support\AuditActor;
use Illuminate\Console\Command;

/**
 * بعد رفع TextNormalizer::VERSION (E30.2): يعيد حساب body_norm لكل الرسائل بالقواعد الجديدة، فالاستعلام والصفوف
 * القديمة يُطبَّعان بالقواعد نفسها. لا يفعل شيئاً إن طابق الإصدار المسجّل إلا مع --force. أعداد فقط.
 */
class WhatsAppSearchRenormalize extends Command
{
    protected $signature = 'whatsapp:search-renormalize {--force : ولو طابق الإصدار المسجّل}';

    protected $description = 'إعادة حساب نص البحث المطبَّع (body_norm) بعد تغيّر قواعد التطبيع';

    public function handle(): int
    {
        $current = FtsSchema::normVersion();
        if ($current === TextNormalizer::VERSION && !$this->option('force')) {
            $this->info('الإصدار '.TextNormalizer::VERSION.' مطبَّق: لا شيء يُعاد (--force للإعادة)');

            return self::SUCCESS;
        }

        $changed = AuditActor::as('system', 'artisan:whatsapp:search-renormalize', function () use ($current) {
            $changed = FtsSchema::renormalize();
            AuditLog::safe(AuditAction::WaSearchIndexRepair, 'system', null, null,
                ['reason' => ['renormalize'], 'from' => $current, 'to' => TextNormalizer::VERSION, 'rows' => $changed]);

            return $changed;
        });
        $this->info("أُعيد تطبيع {$changed} رسالة إلى الإصدار ".TextNormalizer::VERSION);

        return self::SUCCESS;
    }
}
