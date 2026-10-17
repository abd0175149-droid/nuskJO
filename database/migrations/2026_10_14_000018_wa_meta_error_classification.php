<?php

use App\Services\WhatsApp\MetaErrorCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * تصنيف أخطاء ميتا (E18): فئة الخطأ على الرسائل، وفهرس «الفاشل اليوم»، وآخر خطأ على مستوى القناة.
 * إضافات فقط ومحروسة، فلا يُعاد بناء جدول. meta_code وfbtrace_id (E06) وerror_code (E11) تُضاف هنا فقط إن غابت.
 *
 * التعبئة متساوية الأثر على دفعات (500):
 *  1) error_code أرقام صرفة وmeta_code فارغ: meta_code = error_code
 *  2) meta_code موجود وerror_category فارغة: error_code وerror_category من MetaErrorCatalog::classify
 *  3) failed بلا رمز: الرمز من payload._meta_error أو آخر خطأ في statusHistory إن وُجد. لا تخمين من نص error_message
 */
return new class extends Migration
{
    private const INDEX = 'wa_messages_dir_status_created_index';

    public function up(): void
    {
        if (Schema::hasTable('wa_messages')) {
            Schema::table('wa_messages', function (Blueprint $t) {
                if (!Schema::hasColumn('wa_messages', 'meta_code')) {
                    $t->unsignedInteger('meta_code')->nullable();
                }
                if (!Schema::hasColumn('wa_messages', 'fbtrace_id')) {
                    $t->string('fbtrace_id', 64)->nullable();
                }
                if (!Schema::hasColumn('wa_messages', 'error_code')) {
                    $t->string('error_code', 32)->nullable();
                }
                if (!Schema::hasColumn('wa_messages', 'error_category')) {
                    $t->string('error_category', 12)->nullable();
                }
            });
            if (!Schema::hasIndex('wa_messages', self::INDEX)) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->index(['direction', 'status', 'created_at'], self::INDEX));
            }
            $this->backfill();
        }

        if (Schema::hasTable('wa_bot_settings') && !Schema::hasColumn('wa_bot_settings', 'wa_last_error')) {
            Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->json('wa_last_error')->nullable());
        }
    }

    private function backfill(): void
    {
        // (1) رقم ميتا نصاً في error_code (قبل أن يصير رمزاً داخلياً)
        DB::table('wa_messages')->whereNull('meta_code')->whereNotNull('error_code')->select(['id', 'error_code'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    if (preg_match('/^\d{1,9}$/', (string) $row->error_code)) {
                        DB::table('wa_messages')->where('id', $row->id)->update(['meta_code' => (int) $row->error_code]);
                    }
                }
            });

        // (3) فاشلة بلا رمز: من الحمولة وحدها
        DB::table('wa_messages')->where('status', 'failed')->whereNull('meta_code')->whereNotNull('payload')->select(['id', 'payload'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);
                    $code = data_get($payload, '_meta_error.meta_code') ?? data_get(collect((array) data_get($payload, 'statusHistory', []))->last(), 'code');
                    if (is_numeric($code)) {
                        DB::table('wa_messages')->where('id', $row->id)->update(['meta_code' => (int) $code]);
                    }
                }
            });

        // (2) الرمز الداخلي والفئة من الجدول الوحيد
        DB::table('wa_messages')->whereNotNull('meta_code')->whereNull('error_category')->select(['id', 'meta_code'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $e = MetaErrorCatalog::classify((int) $row->meta_code);
                    DB::table('wa_messages')->where('id', $row->id)->update(['error_code' => $e['code'], 'error_category' => $e['category']]);
                }
            });
    }

    /** meta_code وfbtrace_id وerror_code ملك E06 وE11، فلا تُحذف هنا */
    public function down(): void
    {
        if (Schema::hasIndex('wa_messages', self::INDEX)) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->dropIndex(self::INDEX));
        }
        if (Schema::hasColumn('wa_messages', 'error_category')) {
            Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn('error_category'));
        }
        if (Schema::hasColumn('wa_bot_settings', 'wa_last_error')) {
            Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn('wa_last_error'));
        }
    }
};
