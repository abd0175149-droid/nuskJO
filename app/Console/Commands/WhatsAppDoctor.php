<?php

namespace App\Console\Commands;

use App\Models\WaBotSetting;
use App\Services\WhatsApp\ChannelDoctor;
use Illuminate\Console\Command;

/** فحص صحة القناة والإعداد. يطبع فقط (لا سجل)، ويعيد 0 دائماً إلا مع --strict وعند أي تحذير. */
class WhatsAppDoctor extends Command
{
    protected $signature = 'whatsapp:doctor {--strict : يعيد 1 عند أي تحذير أو خطأ}';

    protected $description = 'فحص صحة قناة الواتساب والإعداد (قراءة فقط، بلا أسرار)';

    public function handle(): int
    {
        // المصادر: من أين تأتي كل قيمة، بلا القيمة نفسها (E10)
        $settings = WaBotSetting::current();
        $yesNo = fn (?bool $v) => $v === null ? '—' : ($v ? 'نعم' : 'لا');
        $rows = [];
        foreach ($settings->sources() as $key => $src) {
            $rows[] = [$key, $src['source'], $src['env_var'] ?? '—', $src['db_shadowed'] ? 'نعم' : '—', $yesNo($src['decrypt_ok'])];
        }
        $this->table(['المفتاح', 'المصدر', 'المتغير', 'محفوظ مظلَّل', 'يُفك'], $rows);

        $checks = [...ChannelDoctor::checks(), ...ChannelDoctor::sourceWarnings($settings)];
        if ($checks === []) {
            $this->info('✅ صحة القناة: لا ملاحظات');

            return self::SUCCESS;
        }

        $this->table(['المستوى', 'الرمز', 'الرسالة'], array_map(
            fn ($c) => [$c['level'] === 'error' ? '❌ خطأ' : '⚠️ تحذير', $c['code'], $c['message']],
            $checks,
        ));

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
