<?php

namespace App\Console\Commands;

use App\Services\Ops\InboundSilence;
use Illuminate\Console\Command;

/** صمت الوارد من ميتا في وقت الدوام (E37): يُجدول كل 15 دقيقة، ولا يعمل إلا بالعلم inbound_silence_alert */
class WhatsAppInboundSilence extends Command
{
    protected $signature = 'whatsapp:inbound-silence';

    protected $description = 'تنبيه المدراء إن لم يصل من ميتا أي حدث مدة طويلة في وقت الدوام (بلا بيانات عملاء)';

    private const LABELS = [
        'disabled' => 'العلم inbound_silence_alert مطفأ',
        'outside_hours' => 'خارج الدوام',
        'recent' => 'وصل حدث حديثاً',
        'quiet_period' => 'لا حركة معتادة في هذه الساعة خلال 14 يوماً',
        'already_alerted' => 'نُبّه المدراء خلال آخر 6 ساعات',
        'alerted' => 'أُرسل تنبيه للمدراء',
    ];

    public function handle(): int
    {
        $result = InboundSilence::check();
        $minutes = InboundSilence::minutesSinceLast();
        $this->line((self::LABELS[$result] ?? $result).' · آخر حدث من ميتا: '.($minutes === null ? '—' : "قبل {$minutes} دقيقة"));

        return self::SUCCESS;
    }
}
