<?php

namespace App\Console\Commands;

use App\Models\WaBotSetting;
use App\Services\WhatsApp\ChannelDoctor;
use App\Services\WhatsApp\Graph\GraphClient;
use App\Services\WhatsApp\MediaConverter;
use App\Services\WhatsApp\MetaErrorReactor;
use App\Services\WhatsApp\WebhookStats;
use Illuminate\Console\Command;

/**
 * فحص صحة القناة والإعداد. يطبع فقط (لا سجل)، ويعيد 0 دائماً إلا مع --strict وعند أي تحذير.
 * E23: قسما البيئة (local) وميتا (remote، بميزانية --budget ثوانٍ كلية، و--offline يتخطاه)، و--store يحفظ الحصيلة
 * يومين وينبّه عند خطأ جديد. يعمل عند الإقلاع (entrypoint، بلا منع) ويومياً وبزر «شغّل الفحص».
 */
class WhatsAppDoctor extends Command
{
    protected $signature = 'whatsapp:doctor {--strict : يعيد 1 عند أي تحذير أو خطأ}
        {--budget=5 : ميزانية الفحوص البعيدة بالثواني (لكل نداء 5 حداً أقصى)}
        {--offline : بلا نداء لميتا}
        {--store : احفظ الحصيلة لبطاقة الإعدادات وصفحة الحالة ونبّه عند خطأ جديد}';

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
        // E26: إصدار كروميوم (توليد البطاقات) كما سُجّل عند البناء
        $this->line('chromium: '.(ChannelDoctor::chromiumVersion() ?? 'غير مسجّل (خارج الحاوية أو صورة أقدم)'));
        // E34: ffmpeg لرسائل الموظف الصوتية (بمهلة 5 ثوانٍ)، وأعداد اليوم: التحويلات ومتوسط زمنها و«مشغول» (503) والفشل
        $ffmpeg = MediaConverter::version();
        $voice = MediaConverter::stats();
        $this->line('ffmpeg: '.($ffmpeg !== null ? 'متاح — '.$ffmpeg : 'غير متاح (زر التسجيل مخفي)')
            ." · تحويلات اليوم {$voice['ok']}".($voice['avg_ms'] !== null ? " بمتوسط {$voice['avg_ms']}ms" : '')
            ." · مشغول {$voice['busy']} · فشل {$voice['failed']}");
        // E24: الإصدار المنشور كما خُتم في الصورة
        $build = (array) config('app.build');
        $this->line('الإصدار: '.($build['sha'] ?? 'unknown').(!empty($build['time']) ? ' — بُني '.$build['time'] : ''));

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
        // دفتر الإرسال (E19): رسائل موظف بلا Idempotency-Key (ملف JS قديم في متصفح) اليوم
        if ($missing = (int) \Illuminate\Support\Facades\Cache::get('wa:idem:missing:'.now()->format('Ymd'), 0)) {
            $this->line("رسائل موظف بلا مفتاح منع تكرار اليوم: {$missing} — حدّث الصفحة في المتصفحات المفتوحة");
        }

        // إشعار القراءة ومؤشر الكتابة (E35): الوضع الفعلي والمضبوط، وإخفاقات اليوم، وآخر رمز
        $rr = \App\Services\WhatsApp\ReadReceipts::class;
        $this->line('إشعار القراءة: '.$rr::mode().' (المضبوط '.$rr::configured($settings).'، العلم read_receipts '
            .(\App\Support\FeatureFlags::on('read_receipts') ? 'مفعّل' : 'مطفأ').') · مؤشر الكتابة '.($rr::typingEnabled() ? 'يعمل' : 'لا')
            .' · إخفاقات اليوم '.$rr::failuresToday().($rr::lastFailureCode() ? ' · آخر رمز '.$rr::lastFailureCode() : ''));

        // استقبال الويبهوك (E20): أعداد 24 ساعة، والسياج، والتوقيعات، والحقول المجهولة، وحد nginx المنشور
        $hook = WebhookStats::summary(true);
        $nginx = WebhookStats::nginx();
        $this->line('الويبهوك: الحفظ قبل الإقرار '.($hook['log']['on'] ? "مفعّل ({$hook['log']['mode']})" : 'مطفأ')
            .' · السياج '.($hook['fence']['mode'] === 'enforce' ? 'فرض' : 'تسجيل')
            .' · WABA '.($hook['fence']['source'] ?? 'غير مضبوط')
            .' · nginx '.($nginx['webhook_max'] ?? '؟').' keepalive '.($nginx['keepalive'] ?? '؟').'s');
        $this->table(['الويبهوك (24 ساعة)', 'العدد'], collect($hook['last24'])->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line("توقيعات مرفوضة: ساعة {$hook['signatures']['hour']} · يومان {$hook['signatures']['day']} · ينتظر يدوياً: {$hook['dead_open']} · أقدم جسم: "
            .($hook['oldest_body_hours'] === null ? '—' : $hook['oldest_body_hours'].' ساعة'));
        foreach ($hook['fence']['counts'] as $reason => $c) {
            if ($c['rejected'] || $c['would']) {
                $this->line("سياج {$reason}: مرفوض {$c['rejected']} · كان سيُرفض {$c['would']}");
            }
        }
        foreach ($hook['unknown_fields'] as $field => $n) {
            $this->line("حقل غير معالج: {$field} × {$n}");
        }

        // E23: الفحص الكامل (الإعداد والويبهوك والبيئة وميتا بميزانية). لا يرمي: الإقلاع لا يتوقف عليه
        $result = ChannelDoctor::run(max(1, (int) $this->option('budget')), (bool) $this->option('offline'));
        $icon = ['ok' => '✅', 'warn' => '⚠️', 'error' => '❌', 'skip' => '⏭️'];
        foreach (['local' => 'البيئة', 'remote' => 'ميتا (قراءة فقط)'] as $section => $title) {
            $rows = array_values(array_filter($result['checks'], fn ($c) => $c['section'] === $section));
            $this->table(["فحوص {$title}", 'الرمز', 'الرسالة'], array_map(fn ($c) => [$icon[$c['level']], $c['code'], $c['message']], $rows));
        }
        if ($this->option('store')) {
            ChannelDoctor::store($result);
        }
        $this->line("الحصيلة: {$result['counts']['ok']} سليم · {$result['counts']['warn']} تحذير · {$result['counts']['error']} خطأ · {$result['counts']['skip']} متخطى ({$result['duration_ms']} ms)");

        $checks = array_values(array_filter($result['checks'], fn ($c) => in_array($c['level'], ['warn', 'error'], true)));
        // E34: علم الرسائل الصوتية مفعّل وffmpeg غير موجود في الصورة
        if ($ffmpeg === null && \App\Support\FeatureFlags::on('staff_voice_notes')) {
            $checks[] = ['section' => 'local', 'level' => 'error', 'code' => 'FFMPEG_MISSING', 'message' => 'العلم staff_voice_notes مفعّل وffmpeg غير موجود: زر التسجيل مخفي والمسار يرد 503. أعد بناء الصورة أو أطفئ العلم.'];
        }
        // E24: الإصدار غير مختوم في الإنتاج
        if (app()->isProduction() && ($build['sha'] ?? 'unknown') === 'unknown') {
            $checks[] = ['section' => 'local', 'level' => 'warn', 'code' => 'BUILD_UNSTAMPED', 'message' => 'الإصدار غير مختوم في الصورة: انشر بـ scripts/deploy.sh ليظهر الـ commit (E24).'];
        }
        if ($checks === []) {
            $this->info('✅ صحة القناة: لا ملاحظات');

            return self::SUCCESS;
        }

        $this->table(['المستوى', 'الرمز', 'الرسالة', 'النصيحة'], array_map(
            fn ($c) => [$c['level'] === 'error' ? '❌ خطأ' : '⚠️ تحذير', $c['code'], $c['message'], $c['advice'] ?? ''],
            $checks,
        ));

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
