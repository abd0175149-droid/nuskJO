<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\WaBotSetting;
use App\Models\WaKnowledgeEntry;
use App\Services\WhatsApp\TopicRouter;
use App\Support\SecretRedactor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * استنساخ إعدادات البوت لـ Demo وTawasul (E13.8): قائمة مسموحة لا محظورة، فلا سر ولا تكلفة ولا مستخدم.
 * المنقّح حارس ثانٍ: إن غيّر أي شيء فشل الأمر. لا استيراد: التهيئة من هذا الملف يدوية بعد المراجعة.
 */
class ExportWhatsAppConfig extends Command
{
    protected $signature = 'wa:export-config {--out= : مسار الملف} {--with-offers : العروض بحقول البوت فقط}';

    protected $description = 'تصدير إعدادات البوت بلا أسرار (قائمة أعمدة مسموحة)';

    public const SETTINGS = ['provider', 'model', 'system_prompt', 'context_messages', 'pause_minutes', 'max_tool_loops',
        'rate_limit_per_hour', 'fail_message', 'fail_handoff', 'tools_config', 'model_prices', 'cache_discount', 'feature_flags'];

    public function handle(): int
    {
        $s = WaBotSetting::current();
        $out = [
            'exported_at' => now()->toIso8601String(),
            'wa_bot_settings' => collect(self::SETTINGS)->mapWithKeys(fn ($k) => [$k => $s->getAttribute($k)])->all(),
            'knowledge' => WaKnowledgeEntry::orderBy('sort_order')->get(['title', 'content', 'is_active', 'sort_order'])->toArray(),
            'topics' => TopicRouter::TOPICS,
            // مفاتيح التوجيه بلا مستخدمين (يختلفون بين النسخ)
            'routing_topics' => array_keys(TopicRouter::TOPICS),
        ];
        if ($this->option('with-offers')) {
            $out['offers'] = Offer::forBot()->get()->map(fn ($o) => $o->toBotArray())->values()->all();
        }

        // حارس ثانٍ: المنقّح لا يغيّر شيئاً، ولا قيمة سرية معروفة في الناتج
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secrets = array_filter([$s->token(), $s->appSecret(), $s->llmKey(), $s->wa_verify_token, config('app.key')]);
        if (SecretRedactor::cleanArray($out) != $out || array_filter($secrets, fn ($v) => str_contains($json, (string) $v))) {
            $this->error('الناتج يحمل ما يشبه سراً — لم يُكتب شيء.');

            return self::FAILURE;
        }

        $path = $this->option('out') ?: storage_path('app/exports/wa-config-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path), 0700);
        File::put($path, $json);
        @chmod($path, 0600);

        AuditLog::log(AuditAction::ConfigExport, 'wa_bot_settings', null, null, ['keys' => array_keys($out), 'with_offers' => (bool) $this->option('with-offers')]);
        $this->info($path);

        return self::SUCCESS;
    }
}
