<?php

namespace Tests\Feature\Infra;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * حارس بيانات العملاء في tests/Fixtures.
 *
 * المستودع عام، فلا يجوز أن يدخله رقم عميل أو جواز أو حمولة منسوخة من wa_messages.payload.
 * يفحص كل ملف تحت tests/Fixtures ويفشل عند:
 *   - رقم أردني 9627XXXXXXXX أو 07XXXXXXXX (بالأرقام اللاتينية أو العربية، خاماً أو بعد فك JSON).
 *   - نمط يشبه رقم جواز، أو مفتاح جواز/رقم وطني فيه قيمة.
 *   - رقم هاتف (wa_id وfrom وrecipient_id...) خارج قائمة أرقام أمثلة ميتا، ومنه الرقم المرمّز داخل wamid.
 */
class FixturePiiGuardTest extends TestCase
{
    /** أرقام أمثلة ميتا الوحيدة المسموحة: العميل، ورقمنا المعروض، ورقم آخر في الحساب نفسه */
    private const ALLOWED_PHONES = ['16505551234', '15550783881', '16505553333'];

    /** المفاتيح التي تحمل رقم هاتف في حمولات ميتا */
    private const PHONE_KEYS = ['wa_id', 'from', 'recipient_id', 'display_phone_number', 'phone_number', 'input', 'to'];

    private const PASSPORT_KEYS = '/passport|national_?id|national_?number|id_?number/i';

    public function test_fixture_directory_is_not_empty(): void
    {
        $files = $this->fixtureFiles();

        // حارس لا يجد ملفات لا يحرس شيئاً
        $this->assertGreaterThanOrEqual(20, count($files));
        $this->assertContains('whatsapp/text.json', array_keys($files));
        $this->assertContains('whatsapp/PROVENANCE.txt', array_keys($files));
    }

    public function test_no_fixture_contains_customer_data(): void
    {
        $violations = [];
        foreach ($this->fixtureFiles() as $name => $path) {
            foreach ($this->violationsIn((string) file_get_contents($path)) as $v) {
                $violations[] = "{$name}: {$v}";
            }
        }

        $this->assertSame([], $violations, "بيانات تشبه بيانات العملاء في tests/Fixtures:\n".implode("\n", $violations));
    }

    public function test_every_json_fixture_parses(): void
    {
        foreach ($this->fixtureFiles() as $name => $path) {
            if (str_ends_with($name, '.json')) {
                json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every_whatsapp_fixture_is_listed_in_provenance(): void
    {
        $provenance = (string) file_get_contents(base_path('tests/Fixtures/whatsapp/PROVENANCE.txt'));

        foreach (glob(base_path('tests/Fixtures/whatsapp/*.json')) as $path) {
            $this->assertStringContainsString(basename($path), $provenance, basename($path).' بلا مصدر في PROVENANCE.txt');
        }
    }

    /**
     * الحارس نفسه يلتقط ما وُضع لأجله. القيم تُبنى وقت التشغيل حتى لا يحمل الملف رقماً بهذا الشكل.
     */
    public function test_guard_detects_each_pattern(): void
    {
        $jo = '9627'.str_repeat('0', 8);
        $local = '07'.str_repeat('0', 8);
        $arabicDigits = strtr($local, ['0' => '٠', '7' => '٧']);
        $passport = 'N'.str_repeat('0', 7);
        $wamid = 'wamid.'.base64_encode("\x1c\x18\x0b".'1'.str_repeat('0', 10)."\x15\x02\x00\x12\x18\x14ABCDEF\x00");

        $cases = [
            'international' => '{"body": "call me on +'.$jo.'"}',
            'with 00 prefix' => '{"body": "00'.$jo.'"}',
            'local 07' => '{"body": "'.$local.'"}',
            'arabic-indic digits' => '{"body": "رقمي '.$arabicDigits.'"}',
            'json-escaped digits' => '{"body": "'.implode('', array_map(fn ($c) => sprintf('\\u%04x', ord($c)), str_split($local))).'"}',
            'passport pattern' => '{"body": "جوازي '.$passport.'"}',
            'passport key' => '{"passport_no": "anything"}',
            'wa_id not allowlisted' => '{"contacts": [{"wa_id": "1'.str_repeat('0', 10).'"}]}',
            'from not allowlisted' => '{"messages": [{"from": "1'.str_repeat('0', 10).'"}]}',
            'number hidden in wamid' => '{"id": "'.$wamid.'"}',
        ];

        foreach ($cases as $label => $content) {
            $this->assertNotSame([], $this->violationsIn($content), "الحارس لم يلتقط: {$label}");
        }

        $this->assertSame([], $this->violationsIn((string) file_get_contents(base_path('tests/Fixtures/whatsapp/text.json'))));
    }

    /** @return array<string, string> المسار النسبي => المسار الكامل */
    private function fixtureFiles(): array
    {
        $root = base_path('tests/Fixtures');
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    /** @return list<string> */
    private function violationsIn(string $raw): array
    {
        $violations = [];
        $decoded = json_decode($raw, true);

        // النص الخام، ثم القيم بعد فك JSON (يكشف الأرقام المكتوبة 0...)
        $texts = [$raw];
        if (is_array($decoded)) {
            array_walk_recursive($decoded, function ($v) use (&$texts) {
                if (is_string($v)) {
                    $texts[] = $v;
                }
            });
        }

        foreach ($texts as $text) {
            $text = $this->latinDigits($text);
            foreach ([
                'رقم أردني' => '/(?<!\d)(?:00)?9627\d{8}(?!\d)/',
                'رقم أردني محلي' => '/(?<!\d)07\d{8}(?!\d)/',
                'يشبه رقم جواز' => '/(?<![A-Za-z0-9])[A-Z]{1,2}\d{6,8}(?![A-Za-z0-9])/',
            ] as $label => $pattern) {
                if (preg_match($pattern, $text, $m)) {
                    $violations[] = "{$label} ({$this->mask($m[0])})";
                }
            }

            // wamid يرمّز رقم الطرف الآخر داخل base64
            if (preg_match_all('/wamid\.([A-Za-z0-9+\/_=-]+)/', $text, $wamids)) {
                foreach ($wamids[1] as $b64) {
                    $bytes = (string) base64_decode(strtr($b64, '-_', '+/'));
                    preg_match_all('/\d{10,}/', $bytes, $runs);
                    foreach ($runs[0] as $run) {
                        if (!in_array($run, self::ALLOWED_PHONES, true)) {
                            $violations[] = "رقم داخل wamid خارج القائمة البيضاء ({$this->mask($run)})";
                        }
                    }
                }
            }
        }

        if (is_array($decoded)) {
            $this->walkKeys($decoded, function (string $key, $value) use (&$violations) {
                if (in_array($key, self::PHONE_KEYS, true) && (is_string($value) || is_int($value))
                    && !in_array((string) $value, self::ALLOWED_PHONES, true)) {
                    $violations[] = "{$key} خارج القائمة البيضاء ({$this->mask((string) $value)})";
                }
                if (preg_match(self::PASSPORT_KEYS, $key) && $value !== null && $value !== '') {
                    $violations[] = "مفتاح جواز/هوية فيه قيمة ({$key})";
                }
            });
        }

        return array_values(array_unique($violations));
    }

    private function walkKeys(array $data, callable $visit): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $visit($key, $value);
            }
            if (is_array($value)) {
                $this->walkKeys($value, $visit);
            }
        }
    }

    /** الأرقام العربية (٠-٩) والفارسية (۰-۹) إلى لاتينية */
    private function latinDigits(string $s): string
    {
        $map = [];
        foreach (range(0, 9) as $d) {
            $map[mb_chr(0x0660 + $d)] = (string) $d;
            $map[mb_chr(0x06F0 + $d)] = (string) $d;
        }

        return strtr($s, $map);
    }

    /** لا نطبع الرقم كاملاً في رسالة الفشل (سجلات CI عامة) */
    private function mask(string $s): string
    {
        return strlen($s) <= 4 ? '****' : substr($s, 0, 3).str_repeat('*', strlen($s) - 5).substr($s, -2);
    }
}
