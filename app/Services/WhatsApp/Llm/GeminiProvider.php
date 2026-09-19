<?php

namespace App\Services\WhatsApp\Llm;

use Illuminate\Support\Facades\Http;

/**
 * Google Gemini — generateContent مع functionDeclarations.
 *
 * ملاحظتان مدفوعتا الثمن من تحليل بوت الإنتاج:
 *  • أعد أجزاء النموذج كما وصلت حرفياً: نماذج التفكير تُرفق thoughtSignature مع
 *    functionCall، وتجريده يجعل المزوّد يرفض الطلب التالي.
 *  • توكنز الإخراج = candidatesTokenCount + thoughtsTokenCount (التفكير يُفوتَر كإخراج).
 */
class GeminiProvider implements LlmProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function key(): string
    {
        return 'google';
    }

    public function models(string $apiKey): array
    {
        $res = Http::timeout(20)->acceptJson()->get(self::BASE . '/models', ['key' => $apiKey]);
        if (!$res->successful()) {
            throw new \RuntimeException('Google: ' . data_get($res->json(), 'error.message', 'HTTP ' . $res->status()));
        }

        $out = [];
        foreach ($res->json('models', []) as $m) {
            // نريد النماذج التي تدعم توليد المحتوى فقط
            if (!in_array('generateContent', (array) data_get($m, 'supportedGenerationMethods', []), true)) {
                continue;
            }
            $id = preg_replace('#^models/#', '', (string) data_get($m, 'name'));
            if (!$id || str_contains($id, 'embedding') || str_contains($id, 'aqa')) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'label' => data_get($m, 'displayName', $id),
                'input_limit' => (int) data_get($m, 'inputTokenLimit', 0),
            ];
        }

        // الأحدث أولاً غالباً بترتيب معاكس للاسم
        usort($out, fn ($a, $b) => strcmp($b['id'], $a['id']));

        return $out;
    }

    public function buildHistory(array $messages): array
    {
        $h = [];
        foreach ($messages as $m) {
            $h[] = [
                'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $m['text']]],
            ];
        }

        return $h;
    }

    public function generate(string $apiKey, string $model, array $system, array $history, array $tools): array
    {
        $body = [
            'systemInstruction' => ['parts' => array_map(fn ($t) => ['text' => $t], $system)],
            'contents' => $history,
            'generationConfig' => [
                'temperature' => 0.6,
                'maxOutputTokens' => 1024,
            ],
        ];

        if ($tools) {
            $body['tools'] = [['functionDeclarations' => array_map([$this, 'declaration'], $tools)]];
        }

        $res = Http::timeout(40)->acceptJson()
            ->post(self::BASE . "/models/{$model}:generateContent?key=" . urlencode($apiKey), $body);

        if (!$res->successful()) {
            throw new \RuntimeException('Gemini HTTP ' . $res->status() . ': '
                . mb_substr((string) data_get($res->json(), 'error.message', $res->body()), 0, 300));
        }

        $json = $res->json() ?? [];
        $parts = (array) data_get($json, 'candidates.0.content.parts', []);

        $text = '';
        $calls = [];
        $i = 0;
        foreach ($parts as $p) {
            if (isset($p['text'])) {
                $text .= (string) $p['text'];
            }
            if (isset($p['functionCall'])) {
                $calls[] = [
                    // Gemini لا يرسل معرّفاً — نولّده للمطابقة الداخلية
                    'id' => 'g' . (++$i),
                    'name' => (string) data_get($p, 'functionCall.name'),
                    'input' => (array) data_get($p, 'functionCall.args', []),
                ];
            }
        }

        $u = (array) data_get($json, 'usageMetadata', []);

        return [
            'text' => trim($text),
            'tool_calls' => $calls,
            // نحفظ الأجزاء كما وصلت (فيها thoughtSignature إن وُجد)
            'raw_assistant' => $parts,
            'usage' => [
                'in' => (int) ($u['promptTokenCount'] ?? 0),
                'out' => (int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0),
                'cache_read' => (int) ($u['cachedContentTokenCount'] ?? 0),
                'cache_write' => 0,
            ],
        ];
    }

    public function appendAssistant(array &$history, mixed $rawAssistant): void
    {
        $history[] = ['role' => 'model', 'parts' => (array) $rawAssistant];
    }

    public function appendToolResults(array &$history, array $results): void
    {
        $parts = [];
        foreach ($results as $r) {
            $parts[] = ['functionResponse' => [
                'name' => $r['name'],
                'response' => (object) $r['output'],
            ]];
        }
        $history[] = ['role' => 'user', 'parts' => $parts];
    }

    /** تحويل إعلان الأداة إلى صيغة Gemini */
    private function declaration(array $tool): array
    {
        $d = [
            'name' => $tool['name'],
            'description' => $tool['description'],
        ];

        $schema = $tool['input_schema'] ?? [];
        $props = (array) ($schema['properties'] ?? []);
        // Gemini يرفض parameters بخصائص فارغة — تُحذف بالكامل حينها
        if ($props) {
            $d['parameters'] = [
                'type' => 'object',
                'properties' => $props,
            ];
            if (!empty($schema['required'])) {
                $d['parameters']['required'] = array_values($schema['required']);
            }
        }

        return $d;
    }
}
