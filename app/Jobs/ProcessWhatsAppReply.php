<?php

namespace App\Jobs;

use App\Models\WaConversation;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * دمج الرسائل المتتالية: كل رسالة واردة تسجّل رمزاً وتؤجّل وظيفة ثانيتين.
 * الوظيفة تنفّذ فقط إن كان رمزها هو الأحدث — فثلاث رسائل متتالية تُنتج ردّاً واحداً.
 * القفل يمنع معالجة متزامنة لنفس المحادثة. الاثنان في الكاش لا الذاكرة، فيصمدان لإعادة التشغيل.
 */
class ProcessWhatsAppReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(
        public int $conversationId,
        public string $token,
    ) {}

    public static function debounceKey(int $convId): string
    {
        return "wa:debounce:{$convId}";
    }

    /** يُستدعى من الويبهوك بعد تخزين الرسالة */
    public static function schedule(int $convId): void
    {
        $token = uniqid('t', true);
        Cache::put(self::debounceKey($convId), $token, now()->addMinutes(5));
        self::dispatch($convId, $token)->delay(now()->addSeconds(2));
    }

    public function handle(): void
    {
        // تجاوزته رسالة أحدث؟ اتركه لها
        if (Cache::get(self::debounceKey($this->conversationId)) !== $this->token) {
            return;
        }

        $lock = Cache::lock("wa:conv:{$this->conversationId}", 90);
        if (!$lock->get()) {
            return;   // معالجة جارية بالفعل
        }

        try {
            $conv = WaConversation::find($this->conversationId);
            if ($conv) {
                BotEngine::reply($conv);
            }
        } finally {
            $lock->release();
        }
    }
}
