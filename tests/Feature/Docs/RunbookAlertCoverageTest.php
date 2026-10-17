<?php

namespace Tests\Feature\Docs;

use App\Support\Ops\RunbookMap;
use Tests\TestCase;

/**
 * كل تنبيه للمدراء يحيل إلى كتيّب موجود (E25). القوائم تُشتق من الكود: نداءات notifyAdmins في app/ وroutes/
 * بمحلّل رموز PHP، والبادئات من config/runbooks.php. لا قائمة مكتوبة باليد إلا الاستثناءات أدناه، ولكل منها سبب.
 *   - نداء OpsAlerts (->notifyAdmins): المفتاح يبدأ بنص حرفي له بادئة مربوطة في config/runbooks.php.
 *   - نداء ثابت (WhatsAppInbox::notifyAdmins): يمرّر الكتيّب بالمعامل runbook، أو هو إشعار عمل في EXCEPTIONS.
 *   - كل RB-NN في الإعداد والكود له مرساة <a id="rb-NN"> في docs/RUNBOOK.md، وكل كتيّب بالقالب الثابت.
 *   - كل بادئة في config/runbooks.php لها صف في مصفوفة docs/RISKS.md بكتيّبها.
 */
class RunbookAlertCoverageTest extends TestCase
{
    /** حقول القالب بالترتيب في كل كتيّب */
    public const FIELDS = ['المحفّز', 'الأثر على العملاء', 'الخطورة والمهلة', 'المتطلبات', 'الخطوات', 'التحقق', 'التراجع', 'الممنوع'];

    /**
     * نداءات ثابتة بلا كتيّب، بعددها في كل ملف وسببها: إشعارات عمل لمحادثة أو مستند بعينه، فعلُها في نصها،
     * لا تنبيه تشغيل. نداء جديد بلا runbook في هذه الملفات يغيّر العدد فيفشل الاختبار.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    public const EXCEPTIONS = [
        'app/Jobs/SendOutageNotice.php' => [1, 'محادثة بعينها تحتاج رداً يدوياً بعد رسالة التحويل'],
        'app/Services/WhatsApp/ReplyState.php' => [1, 'محادثة بعينها تحتاج تدخّلاً بسببها الظاهر'],
        'app/Services/WhatsApp/BotEngine.php' => [2, 'محادثة بعينها حُوّلت لموظف، ورقم إدارة ينتظر تفعيل صاحبه للتحقق'],
        'app/Services/WhatsApp/HandoffNotice.php' => [1, 'إشعار التحويل لموظف'],
        'app/Services/WhatsApp/ContactIdentity.php' => [2, 'تغيّر هوية عميل ومحادثتان لعميل واحد: مراجعة ربط'],
        'app/Services/WhatsApp/CallEventHandler.php' => [1, 'مكالمة فائتة تحتاج متابعة'],
        'app/Services/WhatsApp/BlockService.php' => [1, 'إعلام بحظر نفّذه مدير'],
        'app/Services/WhatsApp/ErasureRequests.php' => [1, 'طلب حذف بيانات له صفحته ومهلته'],
        'app/Console/Commands/WhatsAppErasureSla.php' => [1, 'طلب حذف يقترب من مهلته، وخطواته في صفحته'],
        'app/Console/Commands/WakeSnoozedWhatsAppConversations.php' => [1, 'محادثة مؤجلة عاد موعدها'],
        'app/Listeners/WhatsApp/NotifyDeliveryFailure.php' => [1, 'رسالة بعينها لم تصل، وسببها وإجراؤها في النص'],
        'app/Http/Controllers/WhatsAppSettingsController.php' => [1, 'إعلام بإيقاف أو استئناف يدوي باسم فاعله'],
        'app/Http/Controllers/WhatsAppChannelHealthController.php' => [1, 'إعلام برفع حجب يدوي باسم فاعله'],
        'app/Http/Controllers/PayrollController.php' => [1, 'اعتماد رواتب'],
        'app/Http/Controllers/UserController.php' => [1, 'إعلام بإعادة تعيين عامل ثانٍ باسم فاعله (الكتيّب RB-16 لمن فقده)'],
        'app/Services/Security/MfaAttempts.php' => [1, 'قفل مؤقت آلي لحساب موظف؛ المراجعة في RB-16'],
        'app/Services/NotificationService.php' => [5, 'اعتمادات مالية بانتظار المدير'],
        'app/Console/Commands/UsersIdleReport.php' => [1, 'تقرير أسبوعي للحسابات الخاملة'],
    ];

    /**
     * نداءات OpsAlerts بمفتاح يُبنى في متغير، ببادئته الحرفية. الملف الغائب يُتجاهل (E24: OpsRules يبني ops:<القاعدة>).
     *
     * @var array<string, array{0: int, 1: string}>
     */
    public const VARIABLE_KEYS = [
        'app/Services/Ops/OpsRules.php' => [1, 'ops'],
    ];

    private function runbook(): string
    {
        return (string) file_get_contents(base_path('docs/RUNBOOK.md'));
    }

    /** @return array<string, string> RB-NN => نص القسم */
    private function sections(): array
    {
        preg_match_all('/^## (RB-\d{2}) — [^\n]+\n(.*?)(?=^## |\z)/msu', $this->runbook(), $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($s) => [$s[1] => $s[2]])->all();
    }

    private function hasAnchor(string $rb): bool
    {
        return str_contains($this->runbook(), '<a id="'.strtolower($rb).'"></a>');
    }

    /** @return list<string> ملفات PHP في app/ وroutes/ بمسار نسبي */
    private function sources(): array
    {
        $out = [];
        foreach (['app', 'routes'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() === 'php') {
                    // substr لا str_replace: جذر المشروع /app في حاوية البوابة يتكرر داخل /app/app/…
                    $out[] = ltrim(substr($file->getPathname(), strlen(base_path())), '/');
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * كل نداء notifyAdmins في الملف: ثابت أو على كائن، ومعاملاته رموزاً.
     *
     * @return list<array{static: bool, line: int, args: list<list<array|string>>}>
     */
    public static function calls(string $code): array
    {
        $tokens = array_values(array_filter(token_get_all($code), fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $calls = [];
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'notifyAdmins' || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            if (!is_array($prev) || !in_array($prev[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;   // التعريف function notifyAdmins(
            }
            $args = [[]];
            $depth = 0;
            for ($j = $i + 1; $j < count($tokens); $j++) {
                $tok = $tokens[$j];
                $text = is_array($tok) ? $tok[1] : $tok;
                if (in_array($text, ['(', '[', '{'], true) || (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    if ($depth++ === 0) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if (--$depth === 0) {
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $args[] = [];

                    continue;
                }
                $args[count($args) - 1][] = $tok;
            }
            $calls[] = ['static' => $prev[0] === T_DOUBLE_COLON, 'line' => $t[2], 'args' => array_values(array_filter($args))];
        }

        return $calls;
    }

    /** @param list<array|string> $arg */
    private static function named(array $arg): ?string
    {
        return is_array($arg[0] ?? null) && $arg[0][0] === T_STRING && ($arg[1] ?? null) === ':' ? $arg[0][1] : null;
    }

    /** النص الحرفي في أول المعامل ('meta:send:'.$code أو "meta:{$kind}") أو null */
    private static function leadingLiteral(array $arg): ?string
    {
        $first = $arg[0] ?? null;
        if (is_array($first) && $first[0] === T_CONSTANT_ENCAPSED_STRING) {
            return stripcslashes(substr($first[1], 1, -1));
        }
        if ($first === '"' && is_array($arg[1] ?? null) && $arg[1][0] === T_ENCAPSED_AND_WHITESPACE) {
            return $arg[1][1];
        }

        return null;
    }

    public function test_every_runbook_follows_the_fixed_template(): void
    {
        $sections = $this->sections();
        $this->assertGreaterThanOrEqual(15, count($sections), 'الكتيّبات الخمسة عشر الأولى على الأقل');

        foreach ($sections as $rb => $body) {
            $this->assertStringStartsWith('<a id="'.strtolower($rb).'"></a>', ltrim($body), "{$rb}: المرساة أول سطر بعد العنوان");
            $offset = 0;
            foreach (self::FIELDS as $field) {
                $at = mb_strpos($body, "**{$field}:**", $offset);
                $this->assertNotFalse($at, "{$rb}: الحقل «{$field}» غائب أو في غير ترتيبه في docs/RUNBOOK.md");
                $offset = $at;
            }
        }
        $this->assertSame(array_keys($sections), array_values(array_unique(array_keys($sections))));
    }

    public function test_every_runbook_id_in_config_and_code_has_an_anchor(): void
    {
        $ids = array_merge(array_values(config('runbooks.alerts')), array_values(config('runbooks.schedules')));
        foreach ($this->sources() as $file) {
            preg_match_all("/'(RB-\\d{2})'/", (string) file_get_contents(base_path($file)), $m);
            $ids = array_merge($ids, $m[1]);
        }

        $missing = array_values(array_unique(array_filter($ids, fn ($rb) => preg_match(RunbookMap::PATTERN, $rb) !== 1 || !$this->hasAnchor($rb))));
        $this->assertSame([], $missing, 'كتيّبات بلا مرساة <a id="rb-NN"></a> في docs/RUNBOOK.md: '.implode('، ', $missing));
    }

    public function test_every_alert_prefix_has_a_row_in_the_response_matrix(): void
    {
        $risks = (string) file_get_contents(base_path('docs/RISKS.md'));
        $problems = [];
        foreach (config('runbooks.alerts') as $prefix => $rb) {
            $row = collect(explode("\n", $risks))->first(fn ($line) => str_starts_with($line, "| `{$prefix}` |"));
            if ($row === null) {
                $problems[] = "البادئة «{$prefix}» في config/runbooks.php بلا صف في مصفوفة docs/RISKS.md: أضف «| `{$prefix}` | المصدر | {$rb} | الخطورة |»";
            } elseif (!str_contains($row, "| {$rb} |")) {
                $problems[] = "صف «{$prefix}» في docs/RISKS.md لا يذكر {$rb} كما في config/runbooks.php";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_every_admin_alert_in_the_code_carries_a_runbook(): void
    {
        $problems = [];
        $staticWithout = [];
        $variable = [];
        foreach ($this->sources() as $file) {
            foreach (self::calls((string) file_get_contents(base_path($file))) as $call) {
                $where = "{$file}:{$call['line']}";
                if ($call['static']) {
                    $named = array_filter(array_map(fn ($a) => self::named($a), $call['args']));
                    $positional = count(array_filter($call['args'], fn ($a) => self::named($a) === null));
                    if (!in_array('runbook', $named, true) && $positional < 4) {
                        $staticWithout[$file] = ($staticWithout[$file] ?? 0) + 1;
                    }

                    continue;
                }
                $key = self::leadingLiteral($call['args'][0] ?? []);
                if ($key === null) {
                    $variable[$file] = ($variable[$file] ?? 0) + 1;
                } elseif (RunbookMap::for(rtrim($key, ':')) === null && RunbookMap::for($key) === null) {
                    $problems[] = "{$where}: مفتاح التنبيه «{$key}…» بلا بادئة في config/runbooks.php (alerts) ولا صف في docs/RISKS.md";
                }
            }
        }

        foreach ($staticWithout as $file => $n) {
            [$expected, $reason] = self::EXCEPTIONS[$file] ?? [0, ''];
            if ($n !== $expected) {
                $problems[] = "{$file}: {$n} نداء notifyAdmins بلا runbook والمتوقع {$expected}. مرّر runbook: 'RB-NN'، أو إن كان إشعار عمل فحدّث EXCEPTIONS في RunbookAlertCoverageTest بسببه".($reason ? " ({$reason})" : '');
            }
        }
        foreach (self::EXCEPTIONS as $file => [$expected]) {
            if (!isset($staticWithout[$file])) {
                $problems[] = "{$file}: استثناء بلا نداء متبقٍّ ({$expected} متوقعة): احذفه من EXCEPTIONS";
            }
        }
        foreach ($variable as $file => $n) {
            [$expected, $prefix] = self::VARIABLE_KEYS[$file] ?? [0, null];
            if ($n !== $expected || RunbookMap::for((string) $prefix) === null) {
                $problems[] = "{$file}: {$n} تنبيه OpsAlerts بمفتاح في متغير: ابدأه بنص حرفي له بادئة في config/runbooks.php، أو سمِّ بادئته في VARIABLE_KEYS";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_parser_sees_runbooks_and_literal_prefixes(): void
    {
        $code = <<<'PHP'
        <?php
        WhatsAppInbox::notifyAdmins('t', 'b', '/u', runbook: 'RB-03');
        WhatsAppInbox::notifyAdmins($t, "x {$y}", '/u', 'RB-04');
        WhatsAppInbox::notifyAdmins('t', fn ($a) => [$a, 'b'], '/u');
        app(OpsAlerts::class)->notifyAdmins('meta:send:'.$code, 't', "[{$code}] b");
        $this->alerts->notifyAdmins("meta:{$kind}:{$state}", ...$m);
        $alerts->notifyAdmins($key, 't', 'b');
        function notifyAdmins(string $key) {}
        PHP;

        $calls = self::calls($code);
        $this->assertCount(6, $calls);
        $this->assertSame('runbook', self::named($calls[0]['args'][3]));
        $this->assertCount(4, $calls[1]['args']);
        $this->assertCount(3, $calls[2]['args']);
        $this->assertSame('meta:send:', self::leadingLiteral($calls[3]['args'][0]));
        $this->assertSame('meta:', self::leadingLiteral($calls[4]['args'][0]));
        $this->assertNull(self::leadingLiteral($calls[5]['args'][0]));
        $this->assertNull(RunbookMap::for('newthing:x'), 'بادئة غير مربوطة يُبلَّغ عنها');
    }
}
