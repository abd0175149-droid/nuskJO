<?php

namespace Tests\Concerns;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

/** يلتقط ما يُكتب في السجل من أي قناة (WaLog يكتب في قناة whatsapp لا في الواجهة الافتراضية). */
trait CapturesLogs
{
    /** @var list<array{level: string, message: string, context: array}> */
    protected array $logged = [];

    protected function captureLogs(): void
    {
        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    protected function assertLogged(string $level, string $message, ?array $context = null): void
    {
        $match = collect($this->logged)->first(fn ($l) => $l['level'] === $level && $l['message'] === $message
            && ($context === null || $l['context'] == $context));

        $this->assertNotNull($match, "لم يُسجَّل [{$level}] {$message}. المسجَّل: ".json_encode(array_column($this->logged, 'message'), JSON_UNESCAPED_UNICODE));
    }
}
