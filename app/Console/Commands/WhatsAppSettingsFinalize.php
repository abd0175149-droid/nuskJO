<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\BotEngine;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\Settings\SettingsWriter;
use App\Support\AuditActor;
use Illuminate\Console\Command;

/**
 * تنظيف إعدادات البوت (E10): يحذف مفاتيح أدوات لا يعرفها الكود، ومع --reset-prompt يعيد الموجّه إلى الافتراضي الحي
 * إن كان نسخة منه. (مسح رمز تحقق ميتا الصريح أُزيل مع ميتا، 2026-11-06)
 */
class WhatsAppSettingsFinalize extends Command
{
    protected $signature = 'whatsapp:settings-finalize {--dry-run : يطبع ما سيُفعل ولا يكتب} {--reset-prompt : يعيد الموجّه إلى الافتراضي الحي إن طابق نسخة منه}';

    protected $description = 'تنظيف إعدادات الواتساب: الأدوات المجهولة والموجّه المنسوخ من الافتراضي';

    public function handle(): int
    {
        $s = WaBotSetting::current();
        $changes = [];
        $actions = [];

        $unknown = array_values(array_diff(array_keys($s->tools_config ?? []), BotTools::names()));
        if ($unknown !== []) {
            $changes['tools_config'] = array_fill_keys($unknown, null);
            $actions[] = 'unknown_tools_removed';
            $this->line('• تُحذف مفاتيح أدوات مجهولة: '.implode('، ', $unknown));
        }
        // أداة لا تُطفأ وكان لها مفتاح قديم (E15.4): يُحذف، والكاتب يُسقطه أيضاً مع أي حفظ للأدوات
        $locked = array_values(array_filter(array_keys($s->tools_config ?? []), fn ($t) => in_array($t, BotTools::names(), true) && !BotTools::disableable($t)));
        if ($locked !== []) {
            // أي خريطة أدوات (ولو فارغة) تمر بدمج الكاتب فيُسقط المفتاح المقفل
            $changes['tools_config'] ??= [];
            $actions[] = 'locked_tools_cleared';
            $this->line('• تُحذف مفاتيح أدوات لا تُطفأ: '.implode('، ', $locked));
        }

        if ($this->option('reset-prompt') && $s->system_prompt !== null
            && hash('sha256', trim($s->system_prompt)) === hash('sha256', trim(BotEngine::DEFAULT_PROMPT))) {
            $changes['system_prompt'] = null;
            $actions[] = 'prompt_reset';
            $this->line('• يعود الموجّه إلى الافتراضي الحي (كان نسخة مطابقة منه)');
        }

        if ($changes === []) {
            $this->info('✅ لا شيء للإقفال');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $this->warn('تجربة: لم يُكتب شيء');

            return self::SUCCESS;
        }

        AuditActor::as('system', 'artisan:'.$this->getName(), fn () => SettingsWriter::apply($changes, null, AuditAction::WaSettingsFinalize,
            ['actions' => $actions, 'unknown_tools' => $unknown], label: 'إقفال ترقية الإعدادات'));
        $this->info('✅ تم');

        return self::SUCCESS;
    }
}
