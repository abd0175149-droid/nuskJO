<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * يولّد docs/whatsapp/capabilities.md من config/whatsapp_capabilities.php (E25). للمطوّر وCI وحدهما: لا مسار ويب،
 * ولا شبكة، ولا قاعدة. مع --check يقارن الناتج بالملف الموجود ولا يكتب، ويعيد 1 إن اختلفا.
 */
class DocsCapabilities extends Command
{
    protected $signature = 'docs:capabilities {--check : يقارن بالملف الموجود دون كتابة، ويعيد 1 إن اختلفا}';

    protected $description = 'توليد مصفوفة قدرات WhatsApp Cloud API في docs/whatsapp/capabilities.md';

    public const PATH = 'docs/whatsapp/capabilities.md';

    /** ترتيب الجدول وتسمية كل حالة */
    public const STATUSES = [
        'implemented' => 'منفّذ ✅',
        'built_unused' => 'مبني بلا مستعمل',
        'planned' => 'مخطّط ⏳',
        'deferred' => 'مؤجل',
        'rejected' => 'مرفوض ⛔',
        'not_implemented' => 'غير منفّذ',
    ];

    public function handle(): int
    {
        $path = base_path(self::PATH);
        $markdown = self::render((array) config('whatsapp_capabilities'));

        if ($this->option('check')) {
            if (!is_file($path) || file_get_contents($path) !== $markdown) {
                $this->error(self::PATH.' لا يطابق config/whatsapp_capabilities.php: شغّل php artisan docs:capabilities');

                return self::FAILURE;
            }
            $this->info(self::PATH.' مطابق');

            return self::SUCCESS;
        }

        file_put_contents($path, $markdown);
        $this->info('كُتب '.self::PATH);

        return self::SUCCESS;
    }

    public static function render(array $matrix): string
    {
        $order = array_flip(array_keys(self::STATUSES));
        $rows = collect($matrix['rows'] ?? [])->values()
            ->sortBy(fn ($row, $i) => sprintf('%02d-%04d', $order[$row['status']] ?? 99, $i))
            ->values();

        $lines = [
            '# مصفوفة قدرات WhatsApp Cloud API',
            '',
            '> مولَّد — لا تحرّره يدوياً. المصدر `config/whatsapp_capabilities.php`، والتوليد `php artisan docs:capabilities`، والفحص `php artisan docs:capabilities --check`.',
            '',
            'ما بنيناه من قدرات المنصة الرسمية، وما ينتظر ملحمته، وما رفضناه ولماذا. «منفّذ» يعني أن الكود موجود ومختبر، وقد يكون خلف علم مطفأ.',
            '',
            '| القدرة | الحالة | دوال `WhatsAppChannel` | الملحمة | العلم | السبب أو القرار | المرجع الرسمي |',
            '|---|---|---|---|---|---|---|',
        ];
        foreach ($rows as $row) {
            $methods = implode('، ', array_map(fn ($m) => "`{$m}`", $row['methods'] ?? []));
            $reason = trim(($row['reason'] ?? '').(isset($row['decided']) ? ' ('.$row['decided'].')' : ''));
            $lines[] = '| '.implode(' | ', [
                "`{$row['key']}` {$row['label']}",
                self::STATUSES[$row['status']] ?? $row['status'],
                $methods ?: '—',
                $row['epic'] ?? '—',
                isset($row['flag']) ? "`{$row['flag']}`" : '—',
                $reason !== '' ? $reason : '—',
                "[ميتا]({$row['official']})",
            ]).' |';
        }

        foreach (['bot_tools' => 'أدوات بوت العملاء (`BotTools::TOOLS`)', 'admin_tools' => 'أدوات المساعد الإداري (`AdminTools::TOOLS`)'] as $key => $title) {
            $lines[] = '';
            $lines[] = "## {$title}";
            $lines[] = '';
            $lines[] = '| الأداة | الحالة | تحوّل لموظف | العلم |';
            $lines[] = '|---|---|---|---|';
            foreach ($matrix[$key] ?? [] as $name => $tool) {
                $lines[] = "| `{$name}` | ".(self::STATUSES[$tool['status']] ?? $tool['status']).' | '.(!empty($tool['escalating']) ? 'نعم' : '—')
                    .' | '.(isset($tool['flag']) ? "`{$tool['flag']}`" : '—').' |';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
