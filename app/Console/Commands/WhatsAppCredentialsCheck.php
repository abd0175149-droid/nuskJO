<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\MetaHealth;
use Illuminate\Console\Command;

/** فحص رمز ميتا (debug_token) يومياً: صالح؟ ينتهي قريباً؟ صلاحياته كاملة؟ من تطبيقنا؟ يعيد 0 دائماً. */
class WhatsAppCredentialsCheck extends Command
{
    protected $signature = 'whatsapp:credentials-check';

    protected $description = 'فحص رمز ميتا وصلاحياته وتاريخ انتهائه (قراءة فقط، تنبيه للمدير عند مشكلة)';

    public function handle(MetaHealth $health): int
    {
        if (!config('ops.meta_checks')) {
            $this->line('فحوص ميتا مطفأة (WA_META_CHECKS=false)');

            return self::SUCCESS;
        }

        $r = $health->credentials();
        $this->line('رمز ميتا: '.$r['state'].($r['reasons'] ? ' ('.implode(', ', $r['reasons']).')' : ''));

        return self::SUCCESS;
    }
}
