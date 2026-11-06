<?php

namespace App\Console\Commands;

use App\Models\WaChannel;
use App\Services\WhatsApp\OpenWa\SessionManager;
use Illuminate\Console\Command;

/**
 * رمز ربط رقم من سطر الأوامر: QR (رابط data: يُفتح في المتصفح) أو كود ربط بالهاتف.
 *   php artisan whatsapp:channel-qr 3
 *   php artisan whatsapp:channel-qr 3 --phone=9627XXXXXXXX
 */
class WhatsAppChannelQr extends Command
{
    protected $signature = 'whatsapp:channel-qr {id} {--phone= : كود ربط بدل QR}';

    protected $description = 'عرض رمز QR أو كود ربط رقم واتساب على البوابة';

    public function handle(): int
    {
        $ch = WaChannel::find((int) $this->argument('id'));
        if (!$ch) {
            $this->error('لا رقم بهذا المعرّف.');

            return self::FAILURE;
        }
        if ($phone = $this->option('phone')) {
            $res = SessionManager::pairingCode($ch, (string) $phone);
            if (!$res['ok']) {
                $this->error($res['message']);

                return self::FAILURE;
            }
            $this->info('كود الربط: '.$res['pairing_code']);
            $this->line('واتساب على الهاتف ← الأجهزة المرتبطة ← ربط جهاز ← الربط برقم الهاتف بدلاً من ذلك.');

            return self::SUCCESS;
        }
        $res = SessionManager::qr($ch);
        if (!$res['ok']) {
            $this->error($res['message']);

            return self::FAILURE;
        }
        if ($res['qr'] === null) {
            $this->warn("لا QR الآن (الحالة: {$res['status']}). إن كانت الجلسة مربوطة فلا حاجة له، وإلا أعد المحاولة بعد ثوانٍ.");

            return self::SUCCESS;
        }
        $this->line($res['qr']);

        return self::SUCCESS;
    }
}
