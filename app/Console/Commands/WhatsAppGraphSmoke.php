<?php

namespace App\Console\Commands;

use App\Models\WaMessage;
use App\Services\WhatsApp\GraphVersions;
use App\Services\WhatsApp\WhatsAppChannel;
use Illuminate\Console\Command;

/**
 * فحص قرائي لإصدار Graph قبل الترقية أو بعدها: بيانات رقمنا، واختيارياً تنزيل أحدث رسالة صوتية.
 * لا يرسل أي رسالة، ولا يطبع نص رسالة ولا رقم عميل، ويتخلص من بايتات الملف.
 */
class WhatsAppGraphSmoke extends Command
{
    // --target لأن --version محجوز في Artisan
    protected $signature = 'whatsapp:graph-smoke
        {--target= : الإصدار المراد تجربته (الافتراضي: المضبوط في WA_GRAPH_VERSION)}
        {--media : جرّب تنزيل أحدث رسالة صوتية واردة خلال 7 أيام}';

    protected $description = 'فحص قرائي لإصدار Graph: لا يرسل شيئاً';

    public function handle(): int
    {
        $original = config('services.whatsapp.graph_version');
        $target = $this->option('target') ?: $original;
        if (!GraphVersions::isValid($target)) {
            $this->error("إصدار غير صالح: {$target}");

            return self::FAILURE;
        }

        config(['services.whatsapp.graph_version' => $target]);
        try {
            $info = WhatsAppChannel::phoneNumberInfo();
            if (!$info['ok']) {
                $this->error("Graph {$target}: فشل ({$info['code']}".($info['status'] ? ", HTTP {$info['status']}" : '').')');

                return self::FAILURE;
            }
            $this->info("Graph {$target}: OK");
            foreach ($info['data'] as $field => $value) {
                $this->line("  {$field}: {$value}");
            }

            return $this->option('media') ? $this->media() : self::SUCCESS;
        } finally {
            config(['services.whatsapp.graph_version' => $original]);
        }
    }

    private function media(): int
    {
        $voice = WaMessage::where('direction', 'in')->where('msg_type', 'audio')
            ->where('created_at', '>=', now()->subDays(7))->orderByDesc('id')->first();
        $mediaId = (string) data_get($voice?->payload, 'audio.id', '');
        if ($mediaId === '') {
            $this->warn('  الوسائط: لا رسالة صوتية واردة خلال 7 أيام');

            return self::SUCCESS;
        }

        $media = WhatsAppChannel::fetchMedia($mediaId);
        if (!$media['ok']) {
            $this->error("  الوسائط: فشل ({$media['code']})");

            return self::FAILURE;
        }

        $sha = match ($media['sha256_ok']) {
            true => 'مطابق',
            false => 'غير مطابق',
            null => 'غير متاح',
        };
        $this->line(sprintf('  الوسائط: host=%s mime=%s size=%d sha256=%s', $media['host'], $media['mime'], strlen($media['bytes']), $sha));

        return $media['sha256_ok'] === false ? self::FAILURE : self::SUCCESS;
    }
}
