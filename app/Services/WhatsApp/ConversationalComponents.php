<?php

namespace App\Services\WhatsApp;

use App\Models\WaAutoRule;
use App\Models\WaBotSetting;

/**
 * المكوّنات الرسمية في واتساب (E37): أوامر «/» وأسئلة البداية (ice breakers). ما يضغطه العميل يصل رسالةً نصية عادية،
 * فتطابقها قاعدة exact بدقة. التخزين في wa_bot_settings.conversational_automation: desired (المعتمد عندنا)، وsynced_at،
 * وlast_snapshot (آخر قراءة من ميتا)، وlast_error (الرمز والفئة وfbtrace_id). الحدود من صفحة ميتا للمكوّنات.
 */
final class ConversationalComponents
{
    public const MAX_PROMPTS = 4;

    public const PROMPT_LEN = 80;

    public const MAX_COMMANDS = 30;

    public const COMMAND_LEN = 32;

    public const DESCRIPTION_LEN = 256;

    /** ميتا ترفض الإيموجي في الأسئلة والأوامر */
    private const EMOJI = '/\p{Extended_Pictographic}/u';

    /** @return array<string, mixed> قواعد التحقق قبل أي نداء لميتا */
    public static function rules(): array
    {
        $noEmoji = function (string $attribute, mixed $value, \Closure $fail) {
            if (is_string($value) && preg_match(self::EMOJI, $value)) {
                $fail('لا إيموجي في الأسئلة والأوامر (شرط ميتا).');
            }
        };

        return [
            'prompts' => ['present', 'array', 'max:'.self::MAX_PROMPTS],
            'prompts.*' => ['required', 'string', 'max:'.self::PROMPT_LEN, 'distinct', $noEmoji],
            'commands' => ['present', 'array', 'max:'.self::MAX_COMMANDS],
            'commands.*' => ['array:command_name,command_description'],
            'commands.*.command_name' => ['required', 'string', 'max:'.self::COMMAND_LEN, 'not_regex:/^\s*\//', 'distinct', $noEmoji],
            'commands.*.command_description' => ['required', 'string', 'max:'.self::DESCRIPTION_LEN, $noEmoji],
        ];
    }

    public static function messages(): array
    {
        return [
            'prompts.max' => 'أربعة أسئلة على الأكثر.',
            'prompts.*.max' => 'السؤال حتى '.self::PROMPT_LEN.' حرفاً.',
            'prompts.*.distinct' => 'السؤال مكرر.',
            'commands.max' => self::MAX_COMMANDS.' أمراً على الأكثر.',
            'commands.*.command_name.max' => 'اسم الأمر حتى '.self::COMMAND_LEN.' حرفاً.',
            'commands.*.command_name.not_regex' => 'اكتب اسم الأمر بلا «/» في أوله: واتساب يضيفها.',
            'commands.*.command_name.distinct' => 'اسم الأمر مكرر.',
            'commands.*.command_description.max' => 'التلميح حتى '.self::DESCRIPTION_LEN.' حرفاً.',
        ];
    }

    /** @return array{prompts: list<string>, commands: list<array{command_name: string, command_description: string}>} */
    public static function clean(array $data): array
    {
        return [
            'prompts' => array_values(array_map(fn ($p) => trim((string) $p), $data['prompts'] ?? [])),
            'commands' => array_values(array_map(fn ($c) => [
                'command_name' => trim((string) ($c['command_name'] ?? '')),
                'command_description' => trim((string) ($c['command_description'] ?? '')),
            ], $data['commands'] ?? [])),
        ];
    }

    /** @return array{desired: array, synced_at: ?string, last_snapshot: ?array, last_error: ?array} المحفوظ بشكل ثابت */
    public static function stored(?WaBotSetting $s = null): array
    {
        $raw = (array) (($s ?? WaBotSetting::current())->conversational_automation ?? []);

        return [
            'desired' => [
                'prompts' => array_values((array) data_get($raw, 'desired.prompts', [])),
                'commands' => array_values((array) data_get($raw, 'desired.commands', [])),
            ],
            'synced_at' => $raw['synced_at'] ?? null,
            'last_snapshot' => $raw['last_snapshot'] ?? null,
            'last_error' => $raw['last_error'] ?? null,
        ];
    }

    /** للصفحة: المحفوظ، وهل لكل سؤال وأمر قاعدة exact مفعّلة تطابقه */
    public static function state(?WaBotSetting $s = null): array
    {
        $stored = self::stored($s);
        $exact = self::exactPatterns();

        return $stored + ['linked' => [
            'prompts' => array_map(fn ($p) => in_array(AutoRuleMatcher::normalize((string) $p), $exact, true), $stored['desired']['prompts']),
            'commands' => array_map(fn ($c) => in_array(AutoRuleMatcher::normalize((string) data_get($c, 'command_name', '')), $exact, true), $stored['desired']['commands']),
        ]];
    }

    /** @return list<string> تحذيرات غير مانعة: سؤال أو أمر بلا قاعدة exact مفعّلة (سيجيبه النموذج) */
    public static function warnings(array $desired): array
    {
        $exact = self::exactPatterns();
        $out = [];
        foreach ($desired['prompts'] as $p) {
            if (!in_array(AutoRuleMatcher::normalize($p), $exact, true)) {
                $out[] = "«{$p}» بلا قاعدة مطابقة تامة مفعّلة: سيجيبه النموذج.";
            }
        }
        foreach ($desired['commands'] as $c) {
            if (!in_array(AutoRuleMatcher::normalize($c['command_name']), $exact, true)) {
                $out[] = "الأمر /{$c['command_name']} بلا قاعدة مطابقة تامة مفعّلة: سيجيبه النموذج.";
            }
        }

        return $out;
    }

    /** @return list<string> قيم قواعد exact المفعّلة بعد التطبيع */
    private static function exactPatterns(): array
    {
        if (!AutoRuleEngine::ready()) {
            return [];
        }

        return WaAutoRule::active()->where('match_type', 'exact')->get()
            ->flatMap(fn (WaAutoRule $r) => array_filter((array) $r->patterns_norm, 'is_string'))->unique()->values()->all();
    }
}
