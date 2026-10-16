<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\CallEventHandler;
use App\Services\WhatsApp\CallingSettings;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\AuditActor;
use Illuminate\Console\Command;

/**
 * إعدادات الاتصال عند ميتا للرقم المضبوط (E39): --show قراءة فقط (الافتراضي)، و--apply=ملف.json يطبع الفرق ويطلب تأكيداً
 * ثم يكتب POST {pnid}/settings ويُدقَّق دائماً. يرفض والعلم calls_webhook مطفأ (لا سجل لما سيصل)، ويرفض status=ENABLED
 * بلا طريقة رد معتمدة (كل مكالمة ستفوت وتُسحب أذونات المعاودة)، وأي حقل sip (يوقف أحداث calls): إلا مع --force.
 * الملف {"calling": {...}} أو الكائن نفسه، بالحقول status وcall_icon_visibility وcall_hours وcallback_permission_status.
 * التراجع: --apply بملف فيه status=DISABLED أو call_icon_visibility=DISABLE_ALL.
 */
class WhatsAppCallingSettings extends Command
{
    protected $signature = 'whatsapp:calling-settings {--show : قراءة فقط} {--apply= : مسار ملف JSON بالإعدادات الجديدة} {--force : تجاوز الحراسات بعد قرار مكتوب}';

    protected $description = 'قراءة إعدادات الاتصال عند ميتا أو تطبيقها بتأكيد (لا زر لها في الواجهة)';

    public function handle(): int
    {
        $current = WhatsAppChannel::getCallingSettings();
        if (!$current['ok']) {
            $this->error('تعذّرت القراءة من ميتا: '.($current['error'] ?? $current['code']));

            return self::FAILURE;
        }
        $before = (array) ($current['data'] ?? []);
        CallingSettings::remember($before, 'read');
        $this->line('الرقم: '.WaBotSetting::current()->phoneNumberId());
        $this->table(['الحقل', 'القيمة الآن'], $this->rows($before));

        $path = $this->option('apply');
        if ($path === null || $this->option('show')) {
            return self::SUCCESS;
        }

        $json = is_file((string) $path) ? json_decode((string) file_get_contents((string) $path), true) : null;
        if (!is_array($json)) {
            $this->error('الملف غير موجود أو ليس JSON صالحاً.');

            return self::FAILURE;
        }
        $after = is_array($json['calling'] ?? null) ? $json['calling'] : $json;
        $unknown = array_diff(array_keys($after), [...WhatsAppChannel::CALLING_FIELDS, 'sip']);
        if ($unknown !== []) {
            $this->error('حقول غير معروفة: '.implode('، ', $unknown));

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $refusals = array_filter([
            !CallEventHandler::enabled() ? 'العلم calls_webhook مطفأ: لا شيء يسجّل المكالمات التي ستصل. فعّله أولاً.' : null,
            strtoupper((string) ($after['status'] ?? '')) === 'ENABLED' && (WaBotSetting::current()->calls_answer_mode ?: 'none') === 'none'
                ? 'لا طريقة رد معتمدة (calls_answer_mode=none): كل مكالمة ستفوت، وتُسحب أذونات المعاودة بعد 4 مكالمات بلا رد.' : null,
            array_key_exists('sip', $after) ? 'إعداد SIP يوقف وصول أحداث calls إلى الويبهوك، فيتوقف سجل المكالمات.' : null,
        ]);
        foreach ($refusals as $line) {
            $force ? $this->warn('⚠️ '.$line) : $this->error('⛔ '.$line);
        }
        if ($refusals !== [] && !$force) {
            $this->line('لم يُطبَّق شيء. بعد قرار مكتوب من المالك: --force.');

            return self::FAILURE;
        }

        $this->table(['الحقل', 'الآن', 'بعد التطبيق'], array_map(fn ($key) => [$key, $this->value($before[$key] ?? null), $this->value($after[$key] ?? null)], array_keys($after)));
        if (!$this->confirm('تطبيق هذه الإعدادات على رقم الشركة عند ميتا؟')) {
            $this->line('لم يُطبَّق شيء.');

            return self::SUCCESS;
        }

        $res = WhatsAppChannel::applyCallingSettings($after);
        if (!$res['ok']) {
            $this->error('رفضت ميتا: '.($res['error'] ?? $res['code']));

            return self::FAILURE;
        }
        AuditActor::as('system', 'artisan:whatsapp:calling-settings', fn () => AuditLog::log(AuditAction::WaCallingSettingsApply, 'wa_channel', null,
            (string) ($after['status'] ?? 'settings'), ['before' => CallingSettings::sanitize($before), 'after' => CallingSettings::sanitize($after), 'forced' => $force && $refusals !== []]));

        $fresh = WhatsAppChannel::getCallingSettings();
        CallingSettings::remember($fresh['ok'] ? (array) ($fresh['data'] ?? []) : $after, $fresh['ok'] ? 'read' : 'applied');
        $this->info('✅ طُبّقت. ميتا ترسل ويبهوك account_settings_update بالتغيير.');

        return self::SUCCESS;
    }

    private function rows(array $calling): array
    {
        return array_map(fn ($key) => [$key, $this->value($calling[$key] ?? null)], WhatsAppChannel::CALLING_FIELDS);
    }

    private function value(mixed $v): string
    {
        return $v === null ? '—' : (is_scalar($v) ? (string) $v : mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 120));
    }
}
