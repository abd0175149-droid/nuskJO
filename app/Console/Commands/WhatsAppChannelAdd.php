<?php

namespace App\Console\Commands;

use App\Models\WaChannel;
use App\Services\WhatsApp\OpenWa\SessionManager;
use Illuminate\Console\Command;

/**
 * إضافة رقم واتساب وإعداده على البوابة من سطر الأوامر (قبل صفحة الأرقام أو للطوارئ):
 *   php artisan whatsapp:channel-add "المبيعات" --role=sales
 *   php artisan whatsapp:channel-add --repair=3      (مفتاح وwebhook جديدان لرقم قائم)
 * ثم whatsapp:channel-qr 3 لعرض رمز الربط.
 */
class WhatsAppChannelAdd extends Command
{
    protected $signature = 'whatsapp:channel-add {name? : اسم الرقم كما يظهر للموظفين}
        {--role=* : sales | support | notifications | admin}
        {--repair= : رقم صف قائم لإعادة إعداد مفتاحه وwebhook}
        {--no-start : لا تشغّل الجلسة بعد الإنشاء}';

    protected $description = 'إضافة رقم واتساب على بوابة OpenWA (جلسة ومفتاح محصور وwebhook موقّع)';

    public function handle(): int
    {
        if ($id = $this->option('repair')) {
            $ch = WaChannel::find((int) $id);
            if (!$ch) {
                $this->error('لا رقم بهذا المعرّف.');

                return self::FAILURE;
            }
            $res = SessionManager::repair($ch);

            return $this->report($ch, $res);
        }
        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('اكتب اسم الرقم.');

            return self::FAILURE;
        }
        $roles = array_values(array_intersect((array) $this->option('role'), WaChannel::ROLES));
        $ch = WaChannel::create(['name' => mb_substr($name, 0, 60), 'roles' => $roles ?: ['sales'], 'color' => WaChannel::nextColor(),
            'sort' => (int) WaChannel::max('sort') + 1]);
        $res = SessionManager::provision($ch);
        if ($res['ok'] && !$this->option('no-start')) {
            $res = SessionManager::start($ch);
        }

        return $this->report($ch, $res);
    }

    private function report(WaChannel $ch, array $res): int
    {
        if (!$res['ok']) {
            $this->error("#{$ch->id}: {$res['code']} — {$res['message']}");

            return self::FAILURE;
        }
        $this->info("#{$ch->id} {$ch->name}: جاهز للربط. التالي: php artisan whatsapp:channel-qr {$ch->id}");

        return self::SUCCESS;
    }
}
