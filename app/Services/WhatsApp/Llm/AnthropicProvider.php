<?php

namespace App\Services\WhatsApp\Llm;

use Illuminate\Support\Facades\Http;

/**
 * Anthropic Claude — Messages API مع tool_use.
 * يستفيد من تخزين الموجّه مؤقتاً (cache_control) لخفض كلفة الإدخال.
 */
class AnthropicProvider implements LlmProvider
{
    private const API = 'https://api.anthropic.com/v1';
    private const VERSION = '2023-06-01';

    public function key(): string
    {
        return 'anthropic';
    }

    public function models(string $apiKey): array
    {
        $res = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::VERSION,
        ])->timeout(20)->acceptJson()->get(self::API . '/models', ['limit' => 50]);

        if (!$res->successful()) {
            throw new \RuntimeException('Anthropic: ' . data_get($res->json(), 'error.message', 'HTTP ' . $res->status()));
        }

        $out = [];
        foreach ($res->json('data', []) as $m) {
            $out[] = [
                'id' => (string) data_get($m, 'id'),
                'label' => (string) data_get($m, 'display_name', data_get($m, 'id')),
                'input_limit' => 0,
            ];
        }

        return $out;
    }

    public function buildHistory(array $messages): array
    {
        return array_map(fn ($m) => [
            'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
            'content' => (string) $m['text'],
        ], $messages);
    }

    public function generate(string $apiKey, string $model, array $system, array $history, array $tools): array
    {
        // أول كتلة (الشخصية + قاعدة المعرفة) تُخزَّن مؤقتاً
        $systemBlocks = [];
        foreach (array_values($system) as $i => $text) {
            $b = ['type' => 'text', 'text' => $text];
            if ($i === 0) {
                $b['cache_control'] = ['type' => 'ephemeral'];
            }
            $systemBlocks[] = $b;
        }

        $body = [
            'model' => $model,
            'max_tokens' => 1024,
            'temperature' => 0.6,
            'system' => $systemBlocks,
            'messages' => $history,
        ];
        if ($tools) {
            $body['tools'] = $tools;
        }

        $res = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::VERSION,
            'content-type' => 'application/json',
        ])->timeout(40)->post(self::API . '/messages', $body);

        if (!$res->successful()) {
            throw new \RuntimeException('Claude HTTP ' . $res->status() . ': '
                . mb_substr((string) data_get($res->json(), 'error.message', $res->body()), 0, 300));
        }

        $json = $res->json() ?? [];
        $content = (array) data_get($json, 'content', []);

        $text = '';
        $calls = [];
        foreach ($content as $b) {
            if (data_get($b, 'type') === 'text') {
                $text .= (string) data_get($b, 'text', '');
            }
            if (data_get($b, 'type') === 'tool_use') {
                $calls[] = [
                    'id' => (string) data_get($b, 'id'),
                    'name' => (string) data_get($b, 'name'),
                    'input' => (array) data_get($b, 'input', []),
                ];
            }
        }

        $u = (array) data_get($json, 'usage', []);

        return [
            'text' => trim($text),
            'tool_calls' => $calls,
            'raw_assistant' => $content,
            'usage' => [
                'in' => (int) ($u['input_tokens'] ?? 0),
                'out' => (int) ($u['output_tokens'] ?? 0),
                'cache_read' => (int) ($u['cache_read_input_tokens'] ?? 0),
                'cache_write' => (int) ($u['cache_creation_input_tokens'] ?? 0),
            ],
        ];
    }

    public function appendAssistant(array &$history, mixed $rawAssistant): void
    {
        $history[] = ['role' => 'assistant', 'content' => (array) $rawAssistant];
    }

    public function appendToolResults(array &$history, array $results): void
    {
        $blocks = [];
        foreach ($results as $r) {
            $blocks[] = [
                'type' => 'tool_result',
                'tool_use_id' => $r['id'],
                'content' => json_encode($r['output'], JSON_UNESCAPED_UNICODE),
            ];
        }
        $history[] = ['role' => 'user', 'content' => $blocks];
    }
}
