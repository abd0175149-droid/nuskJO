<?php

namespace App\Console\Commands;

use App\Models\WaBotSetting;
use App\Services\WhatsApp\ChannelDoctor;
use App\Services\WhatsApp\Graph\GraphClient;
use App\Services\WhatsApp\MetaErrorReactor;
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

        // E18: إثبات سر التطبيق آخر 7 أيام (شرط دخول E26: نداءات = بإثبات، وصفر رفض)، وفئات أخطاء ميتا اليوم
        $proof = GraphClient::proofSummary();
        $source = getenv('WA_APPSECRET_PROOF') !== false ? 'env' : 'default';
        $this->line('appsecret_proof: '.($proof['enabled'] ? 'مفعّل' : 'مطفأ')." ({$source})"
            .($proof['percent'] !== null ? " — {$proof['percent']}% من {$proof['calls']} نداء، رفض {$proof['proof_err']}" : '')
            .($proof['ready_for_require'] ? ' — جاهز لـ Require App Secret (E26)' : ''));
        $this->table(['اليوم', 'نداءات', 'بإثبات', 'رفض إثبات'], array_map(fn ($r) => [$r['day'], $r['calls'], $r['proof'], $r['proof_err']], GraphClient::counters()));
        $this->table(['فئة خطأ ميتا (اليوم)', 'العدد'], collect(MetaErrorReactor::categoryCounts())->map(fn ($n, $c) => [$c, $n])->values()->all());
        foreach (MetaErrorReactor::unknownCodes() as $code => $count) {
            $this->line("رمز مجهول اليوم: {$code} × {$count}");
        }

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
