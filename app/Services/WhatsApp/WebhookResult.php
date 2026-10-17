<?php

namespace App\Services\WhatsApp;

use Illuminate\Database\QueryException;

/**
 * نتيجة معالجة حمولة ويبهوك واحدة (E20): ما يُجدول، وأخطاء العناصر بلا نصوص، والأعداد.
 * عنصر فاشل لا يُسقط ما بعده؛ يُسجَّل هنا ويبقى الجسم المشفّر للإعادة.
 */
final class WebhookResult
{
    /** @var array<int, int> معرّفات المحادثات التي وصلها وارد ينتظر رداً */
    public array $touched = [];

    /** @var list<array{kind: string, ref: string, error: string}> */
    public array $errors = [];

    /** @var array<string, int> messages وstatuses ولكل حقل عدده */
    public array $counts = [];

    /** خطأ قاعدة عابر (مقفلة، قرص ممتلئ) في عنصر: بلا سجل محفوظ يُرد 503 خلف webhook_5xx لتعيد ميتا */
    public bool $transient = false;

    public function touch(int $conversationId): void
    {
        $this->touched[$conversationId] = $conversationId;
    }

    /** @return list<int> */
    public function touched(): array
    {
        return array_values($this->touched);
    }

    public function count(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function fail(string $kind, string|int|null $ref, \Throwable $e): void
    {
        $this->errors[] = ['kind' => $kind, 'ref' => mb_substr((string) $ref, 0, 80), 'error' => class_basename($e)];
        // فشل الجدولة لا يُفشل الحمولة: الرسائل خُزّنت، والمُصالِح يلتقطها
        if ($kind !== 'schedule' && ($e instanceof QueryException || $e instanceof \PDOException)) {
            $this->transient = true;
        }
    }

    /** عنصر لم يُعالج (الجدولة وحدها لا تُعدّ) */
    public function failed(): bool
    {
        foreach ($this->errors as $e) {
            if ($e['kind'] !== 'schedule') {
                return true;
            }
        }

        return false;
    }

    /** «messages:2,statuses:5»: أسماء وأعداد فقط، بحد العمود */
    public function summary(): string
    {
        $parts = [];
        foreach ($this->counts as $k => $n) {
            $parts[] = "{$k}:{$n}";
        }

        return mb_substr(implode(',', $parts), 0, 160);
    }
}
