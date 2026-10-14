<?php

namespace App\Console\Commands;

use App\Models\WaMessage;
use App\Services\WhatsApp\Outbound\OutboundPolicy;
use Illuminate\Console\Command;

/**
 * قرارات بوابة المحتوى الصادر (E15.9) للمراجعة قبل الفرض وبعده: الأعداد لكل سبب ومصدر، وأمثلة مقنّعة
 * (الأرقام نقاط). للقراءة فقط.
 */
class WhatsAppPolicyReport extends Command
{
    protected $signature = 'whatsapp:policy-report {--days=7 : الأيام الأخيرة} {--samples=3 : أمثلة مقنّعة لكل سبب}';

    protected $description = 'قرارات سياسة المحتوى الصادر: الأعداد لكل سبب ومصدر، مع أمثلة مقنّعة';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $perReason = max(0, (int) $this->option('samples'));
        $rows = [];
        $samples = [];

        WaMessage::where('direction', 'out')
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('payload->_policy')
            ->orderBy('id')
            ->select(['id', 'source', 'status', 'body', 'payload'])
            ->chunkById(500, function ($messages) use (&$rows, &$samples, $perReason) {
                foreach ($messages as $m) {
                    $policy = (array) ($m->payload['_policy'] ?? []);
                    $blocked = $m->status === 'blocked';
                    foreach (array_merge((array) ($policy['reasons'] ?? []), (array) ($policy['warnings'] ?? [])) as $reason) {
                        $key = $reason.'|'.$m->source;
                        $rows[$key] ??= ['reason' => $reason, 'source' => $m->source, 'count' => 0, 'blocked' => 0];
                        $rows[$key]['count']++;
                        $rows[$key]['blocked'] += $blocked ? 1 : 0;
                        if (count($samples[$reason] ?? []) < $perReason) {
                            $samples[$reason][] = '#'.$m->id.' '.($blocked ? '(محجوبة)' : self::mask((string) $m->body));
                        }
                    }
                }
            });

        if ($rows === []) {
            $this->info("لا قرارات سياسة في آخر {$days} يوماً.");

            return self::SUCCESS;
        }

        $this->info("قرارات سياسة المحتوى الصادر — آخر {$days} يوماً (الفرض: ".(OutboundPolicy::enforced() ? 'مفعّل' : 'تسجيل فقط').')');
        $this->table(['السبب', 'المصدر', 'العدد', 'حُجبت'], array_map(fn ($r) => [
            (OutboundPolicy::LABELS[$r['reason']] ?? $r['reason']).' ('.$r['reason'].')', $r['source'], $r['count'], $r['blocked'],
        ], array_values($rows)));

        foreach ($samples as $reason => $list) {
            $this->line('');
            $this->line('أمثلة — '.(OutboundPolicy::LABELS[$reason] ?? $reason).':');
            foreach ($list as $line) {
                $this->line('  '.$line);
            }
        }

        return self::SUCCESS;
    }

    /** الأرقام وبادئات الرموز نقاط، وسطر واحد حتى 120 حرفاً */
    public static function mask(string $text): string
    {
        $t = preg_replace('/[A-Z]{1,2}(?=\d)/', '••', $text) ?? $text;
        $t = preg_replace('/\p{Nd}/u', '•', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return mb_substr(trim($t), 0, 120);
    }
}
