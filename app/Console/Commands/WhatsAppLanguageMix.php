<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\HandoffGuard;
use App\Services\WhatsApp\MessageTranslator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قياس الحاجة قبل تفعيل الترجمة (E29.1): للقراءة فقط. لكل محادثة فيها وارد خلال --days يوماً يجمع نص آخر 20 رسالة
 * واردة نصية ويطبّق HandoffGuard::isLatin، ويحسب توزيع الكتابة بالحروف وحدها، بلا أي نموذج. يطبع أعداداً ونسباً فقط:
 * لا نص ولا رقم ولا اسم، ولا كتابة في أي مكان (ولا في الكاش). محادثات أرقام الإدارة خارج العدّ.
 */
class WhatsAppLanguageMix extends Command
{
    protected $signature = 'whatsapp:language-mix {--days=90 : الوارد خلال هذه الأيام} {--json : مخرجات JSON}';

    protected $description = 'للقراءة فقط: نسبة محادثات الواتساب بالحروف اللاتينية الغالبة وتوزيع الكتابة (أعداد فقط)';

    private const SAMPLE = 20;

    private const LABELS = [
        'arabic' => 'عربية', 'perso_urdu' => 'فارسية أو أوردية', 'latin' => 'لاتينية', 'devanagari' => 'ديفاناغارية (هندية)',
        'bengali' => 'بنغالية', 'other' => 'أخرى', 'none' => 'بلا حروف (رموز أو أرقام)',
    ];

    public function handle(): int
    {
        $days = max(1, min(3650, (int) $this->option('days')));
        $ids = DB::table('wa_messages')->join('wa_conversations', 'wa_conversations.id', '=', 'wa_messages.conversation_id')
            ->where('wa_messages.direction', 'in')->where('wa_messages.created_at', '>=', now()->subDays($days))
            ->whereNotIn('wa_conversations.status', ['merged', 'erased', 'pruned'])
            ->when(Schema::hasColumn('wa_conversations', 'is_admin_chat'), fn ($q) => $q->where('wa_conversations.is_admin_chat', false))
            ->distinct()->orderBy('wa_messages.conversation_id')->pluck('wa_messages.conversation_id')->map(fn ($id) => (int) $id)->all();

        $scripts = array_fill_keys(array_keys(self::LABELS), 0);
        $latin = 0;
        $latinStaff = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            // آخر 20 وارداً نصياً لكل محادثة باستعلام واحد للدفعة
            $texts = DB::table(DB::raw('(select conversation_id, body, row_number() over (partition by conversation_id order by id desc) as rn
                    from wa_messages where direction = \'in\' and msg_type = \'text\' and conversation_id in ('.implode(',', $chunk).')) as recent'))
                ->where('rn', '<=', self::SAMPLE)->get(['conversation_id', 'body'])
                ->groupBy('conversation_id')->map(fn ($rows) => $rows->pluck('body')->implode("\n"));
            // حُوّلت لموظف: البوت مطفأ فيها، أو تنتظر تدخّلاً، أو ردّ فيها موظف
            $staffed = DB::table('wa_conversations')->whereIn('id', $chunk)
                ->where(fn ($w) => $w->where('bot_enabled', false)->orWhere('needs_attention', true)
                    ->orWhereExists(fn ($q) => $q->selectRaw(1)->from('wa_messages')->whereColumn('wa_messages.conversation_id', 'wa_conversations.id')
                        ->where('wa_messages.direction', 'out')->where('wa_messages.source', 'staff')))
                ->pluck('id')->map(fn ($id) => (int) $id)->flip();

            foreach ($chunk as $id) {
                $text = (string) ($texts[$id] ?? '');
                $scripts[self::script($text)]++;
                if (HandoffGuard::isLatin($text)) {
                    $latin++;
                    $latinStaff += isset($staffed[$id]) ? 1 : 0;
                }
            }
        }

        $total = count($ids);
        $report = [
            'days' => $days,
            'conversations' => $total,
            'latin' => $latin,
            'latin_percent' => $total ? round($latin * 100 / $total, 1) : 0.0,
            'latin_handed_to_staff' => $latinStaff,
            'scripts' => $scripts,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->line("المحادثات ذات وارد خلال {$days} يوماً: {$total}");
        $this->line("بحروف لاتينية غالبة: {$latin} ({$report['latin_percent']}%)، منها حُوّلت لموظف: {$latinStaff}");
        $this->table(['الكتابة الغالبة', 'المحادثات'], array_map(fn ($k) => [self::LABELS[$k], $scripts[$k]], array_keys(self::LABELS)));
        if ($total && $report['latin_percent'] < 5) {
            $this->warn('النسبة أقل من 5%: للمالك أن يؤجّل الترجمة ويشحن التصنيفات والتثبيت وحدهما.');
        }

        return self::SUCCESS;
    }

    /** الكتابة الغالبة بعدّ الحروف وحدها؛ العربية بحرف فارسي أو أوردي واحد فأكثر «فارسية أو أوردية» */
    private static function script(string $text): string
    {
        $counts = [
            'arabic' => preg_match_all('/\p{Arabic}/u', $text),
            'latin' => preg_match_all('/[A-Za-z]/u', $text),
            'devanagari' => preg_match_all('/\p{Devanagari}/u', $text),
            'bengali' => preg_match_all('/\p{Bengali}/u', $text),
        ];
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters === 0) {
            return 'none';
        }
        $counts['other'] = max(0, $letters - array_sum($counts));
        arsort($counts);
        $top = (string) array_key_first($counts);

        return $top === 'arabic' && preg_match(MessageTranslator::PERSO_URDU, $text) ? 'perso_urdu' : $top;
    }
}
