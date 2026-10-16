<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\HistoryHold;
use App\Services\WhatsApp\WaLog;
use App\Support\AuditActor;
use Illuminate\Console\Command;

/**
 * سجل التعايش السابق المحفوظ (C012، E39): الملخص (بلا خيار)، والتحليل على دفعات (--analyze، أعداد وتواريخ وحدها)،
 * والإهمال بقرار (--discard، يُدقَّق)، والمحو بعد المدة (--prune، كل ساعة). لا خيار يطبع رقماً أو نصاً، ولا استيراد هنا:
 * الاستيراد إلى الصندوق ملحمة مستقلة بقرار المالك (docs/whatsapp/coexistence.md).
 */
class WhatsAppCoexistenceHistory extends Command
{
    protected $signature = 'whatsapp:coexistence-history {--analyze : تحليل المحفوظ على دفعات} {--discard : محو المحفوظ كله بقرار}
        {--prune : محو ما مضت مدته} {--force : بلا سؤال (مع --discard)}';

    protected $description = 'ملخص سجل التعايش السابق المحفوظ مشفّراً، وتحليله، وإهماله، ومحوه بعد المدة';

    public function handle(): int
    {
        if (!HistoryHold::available()) {
            $this->warn('جدول wa_history_chunks غير موجود بعد (شغّل الهجرة).');

            return self::SUCCESS;
        }

        if ($this->option('prune')) {
            $n = HistoryHold::prune();
            if ($n > 0) {
                WaLog::warning('coexistence.history_pruned', ['count' => $n]);
            }
            $this->info("مُحيت {$n} دفعة مضت مدتها");

            return self::SUCCESS;
        }

        if ($this->option('discard')) {
            $s = HistoryHold::summary();
            if ($s['held'] === 0) {
                $this->info('لا شيء محفوظ.');

                return self::SUCCESS;
            }
            if (!$this->option('force') && !$this->confirm("محو {$s['held']} دفعة ({$s['messages']} رسالة) نهائياً؟ لا تراجع: ميتا لا تعيد السجل")) {
                $this->line('لم يُمحَ شيء.');

                return self::SUCCESS;
            }
            $n = 0;
            AuditActor::as('system', 'artisan:whatsapp:coexistence-history', function () use (&$n, $s) {
                $n = HistoryHold::discard();
                AuditLog::log(AuditAction::WaHistoryDiscard, 'wa_history', null, 'discard', ['chunks' => $n, 'messages' => $s['messages'], 'threads' => $s['threads']]);
            });
            $this->info("مُحيت {$n} دفعة");

            return self::SUCCESS;
        }

        $s = HistoryHold::summary();
        $this->table(['المحفوظ', 'المحادثات', 'الرسائل', 'أقرب محو', 'مُهمل', 'انتهت مدته'],
            [[$s['held'], $s['threads'], $s['messages'], $s['purge_soonest'] ?? '—', $s['discarded'], $s['expired']]]);

        if ($this->option('analyze')) {
            $a = HistoryHold::analyze((string) WaBotSetting::current()->wa_display_phone);
            $this->line("الدفعات: {$a['chunks']} · الخيوط: {$a['threads']} (لها محادثة عندنا: {$a['existing_threads']}) · الرسائل: {$a['messages']}");
            $this->line("من هاتف الوكالة: {$a['from_business']} · من العملاء: {$a['from_customers']} · المدى: ".($a['oldest'] ?? '—').' ← '.($a['newest'] ?? '—'));
            $this->line('الأنواع: '.(collect($a['types'])->map(fn ($n, $t) => "{$t}={$n}")->implode('، ') ?: '—'));
        }

        return self::SUCCESS;
    }
}
