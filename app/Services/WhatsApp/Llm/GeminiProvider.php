<?php

namespace App\Services\WhatsApp\Llm;

use App\Support\UrlPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    /** المفتاح في الترويسة لا في الرابط (الرابط يصل إلى السجلات ورسائل الأخطاء)، ولا تحويل يحمله لمضيف آخر */
    private function client(#[\SensitiveParameter] string $apiKey, int $timeout): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout($timeout)
            ->acceptJson();
    }

    /** POST واحد: كل فشل يُصنَّف (LlmErrorClassifier)، ورسالته بلا رابط ولا مفتاح ولا تفاصيل cURL */
    private function post(#[\SensitiveParameter] string $apiKey, int $timeout, string $url, array $body): Response
    {
        try {
            $res = $this->client($apiKey, $timeout)->post($url, $body);
        } catch (ConnectionException $e) {
            throw LlmErrorClassifier::fromThrowable('google', $e);
        }

        if (!$res->successful()) {
            throw LlmErrorClassifier::fromResponse('google', $res);
        }

        return $res;
    }

    private function generateUrl(string $model): string
    {
        return self::BASE.'/models/'.UrlPath::segment($model).':generateContent';
    }

    public function models(#[\SensitiveParameter] string $apiKey): array
    {
        try {
            $res = $this->client($apiKey, 20)->get(self::BASE.'/models');
        } catch (ConnectionException $e) {
            throw LlmErrorClassifier::fromThrowable('google', $e);
        }
        if (!$res->successful()) {
            throw LlmErrorClassifier::fromResponse('google', $res);
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

    public function generate(#[\SensitiveParameter] string $apiKey, string $model, array $system, array $history, array $tools, ?int $timeout = null): array
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

        $res = $this->post($apiKey, $timeout ?? 40, $this->generateUrl($model), $body);

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
                    'id' => 'g'.(++$i),
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

    /**
     * تفريغ مقطع صوتي إلى نصّ بالنموذج نفسه (Gemini يقبل الصوت مباشرةً).
     * يُستعمل للرسائل الصوتية في واتساب — ogg/opus غالباً، وصغيرة الحجم.
     *
     * @return array{text:string, usage:array{in:int,out:int}}
     */
    public function transcribe(#[\SensitiveParameter] string $apiKey, string $model, string $bytes, string $mime, ?int $timeout = null): array
    {
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bytes)]],
                    ['text' => 'فرّغ هذه الرسالة الصوتية نصّاً حرفياً بلغتها الأصلية (غالباً عربية بلهجة أردنية). '
                        .'اكتب النصّ المنطوق فقط، بلا مقدّمة ولا شرح ولا ترجمة. '
                        .'إن كانت صامتة أو غير مفهومة فاكتب: [غير مفهومة]'],
                ],
            ]],
            'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 1024],
        ];

        $res = $this->post($apiKey, $timeout ?? 60, $this->generateUrl($model), $body);

        $text = '';
        foreach ((array) data_get($res->json(), 'candidates.0.content.parts', []) as $p) {
            $text .= (string) ($p['text'] ?? '');
        }
        $u = (array) data_get($res->json(), 'usageMetadata', []);

        return [
            'text' => trim($text),
            'usage' => [
                'in' => (int) ($u['promptTokenCount'] ?? 0),
                'out' => (int) ($u['candidatesTokenCount'] ?? 0) + (int) ($u['thoughtsTokenCount'] ?? 0),
            ],
        ];
    }

    public function appendAssistant(array &$history, mixed $rawAssistant): void
    {
        // json_decode يحوّل «args: {}» إلى مصفوفة فارغة فتُرسل «[]»، وGemini يرفض الطلب التالي
        // («Proto field is not repeating»). نعيدها كائناً — وباقي الجزء كما وصل حرفياً.
        $parts = array_map(function ($p) {
            if (is_array($p) && isset($p['functionCall']) && empty($p['functionCall']['args'])) {
                $p['functionCall']['args'] = new \stdClass;
            }

            return $p;
        }, (array) $rawAssistant);

        $history[] = ['role' => 'model', 'parts' => $parts];
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
