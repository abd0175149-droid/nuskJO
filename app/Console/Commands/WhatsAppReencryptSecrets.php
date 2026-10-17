<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\Settings\AppKeys;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Support\AuditActor;
use Illuminate\Console\Command;

/**
 * بعد تدوير APP_KEY (المفتاح القديم في APP_PREVIOUS_KEYS): يعيد تشفير أسرار القناة بالمفتاح الحالي، ثم يتحقق
 * بالمفتاح الحالي وحده. بعده يمكن حذف APP_PREVIOUS_KEYS. لا يطبع أي قيمة ولا مفتاح، بصمة قصيرة فقط.
 */
class WhatsAppReencryptSecrets extends Command
{
    protected $signature = 'whatsapp:reencrypt-secrets {--dry-run : يطبع الحالة ولا يكتب}';

    protected $description = 'إعادة تشفير أسرار الواتساب بمفتاح التطبيق الحالي بعد تدويره (بلا أسرار في المخرجات)';

    private const SECRETS = ['wa_token', 'wa_app_secret', 'api_key'];

    public function handle(): int
    {
        $settings = WaBotSetting::current();
        $rows = [];
        $needs = [];
        foreach (self::SECRETS as $column) {
            $raw = $settings->getRawOriginal($column);
            $state = match (true) {
                $raw === null || $raw === '' => 'فارغ',
                AppKeys::decryptsWithCurrent($raw) === true => 'بالمفتاح الحالي',
                $settings->decryptOk($column) === true => 'بمفتاح سابق',
                default => 'لا يُفك',
            };
            if ($state === 'بمفتاح سابق') {
                $needs[] = $column;
            }
            $rows[] = [$column, $state];
        }
        $this->table(['السر', 'يُفك'], $rows);
        $this->line('المفتاح الحالي: '.AppKeys::fingerprint((string) config('app.key')).' · سابقة: '.count(AppKeys::previous()));

        if ($this->option('dry-run') || $needs === []) {
            $this->info($needs === [] ? '✅ لا شيء يحتاج إعادة تشفير' : 'سيُعاد تشفير: '.implode('، ', $needs));

            return in_array('لا يُفك', array_column($rows, 1), true) ? self::FAILURE : self::SUCCESS;
        }

        $result = AuditActor::as('system', 'artisan:'.$this->getName(), function () use ($needs, $settings) {
            $result = SettingsWriter::rewriteEncrypted($needs);
            AuditLog::log(AuditAction::AppKeyReencrypt, 'wa_bot_settings', $settings->id, 'إعادة تشفير الأسرار', [
                'fields' => $result['rewritten'],
                'current_fp' => AppKeys::fingerprint((string) config('app.key')),
                'previous_fps' => array_map(AppKeys::fingerprint(...), AppKeys::previous()),
            ]);

            return $result;
        });

        $fresh = WaBotSetting::current()->fresh();
        $failed = array_filter($result['rewritten'], fn ($c) => AppKeys::decryptsWithCurrent($fresh->getRawOriginal($c)) !== true);
        if ($failed !== [] || $result['unreadable'] !== []) {
            $this->error('لم يكتمل: '.implode('، ', [...$failed, ...$result['unreadable']]));

            return self::FAILURE;
        }
        $this->info('✅ أُعيد تشفير: '.implode('، ', $result['rewritten']).' — يمكن حذف APP_PREVIOUS_KEYS بعد أن تنتهي النسخ المشفّرة بالقديم');

        return self::SUCCESS;
    }
}
