<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\ErasureRequests;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Console\Command;

/** طلب حذف وصل بالبريد أو حضورياً (E13.4): يُسجَّل ببصمة الرقم وحدها، ويُربط بمحادثته إن وُجدت */
class WhatsAppErasureRequest extends Command
{
    protected $signature = 'whatsapp:erasure-request {--phone= : رقم صاحب الطلب} {--channel=email : email|staff}';

    protected $description = 'تسجيل طلب حذف بيانات وصل من خارج الواتساب (لا يُخزَّن الرقم)';

    public function handle(): int
    {
        $canonical = PhoneNormalizer::canonical((string) $this->option('phone'));
        if ($canonical === '' || strlen($canonical) < 8) {
            $this->error('رقم غير صالح');

            return self::FAILURE;
        }
        if (!in_array($this->option('channel'), ['email', 'staff'], true)) {
            $this->error('القناة: email أو staff');

            return self::FAILURE;
        }

        $request = ErasureRequests::openForPhone($canonical, (string) $this->option('channel'));
        $this->info("طلب #{$request->id} ({$request->status})، المهلة {$request->due_at?->toDateString()}".($request->conversation_id ? "، محادثة #{$request->conversation_id}" : '، بلا محادثة'));

        return self::SUCCESS;
    }
}
