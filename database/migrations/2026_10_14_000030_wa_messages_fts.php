<?php

use App\Models\Setting;
use App\Services\WhatsApp\Search\FtsSchema;
use App\Services\WhatsApp\TextNormalizer;
use App\Services\WhatsApp\WaLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * فهرس البحث في الرسائل (E30): FTS5 بمحتوى خارجي على wa_messages.body_norm بمُجزِّئ trigram (يطابق جزء الكلمة،
 * فـ«فندق» تجد «والفندق»)، وثلاثة triggers، وإعادة بناء، في معاملة واحدة. الجمل في App\Services\WhatsApp\Search\FtsSchema
 * ويعيد استعمالها whatsapp:fts-check --repair. لا تعديل على أعمدة wa_messages ولا إعادة بناء له.
 *
 * ⚠️ أي ->change() أو dropColumn أو مفتاح أجنبي على wa_messages بعد هذا الملف قد يعيد بناء الجدول فتسقط الـ triggers
 * بصمت؛ شغّل whatsapp:fts-check --repair بعدها (FtsTriggersSurviveMigrationsTest يرفض ذلك في CI).
 *
 * SQLite بلا FTS5 أو trigram: لا يُنشأ شيء ولا تفشل الهجرة، والبحث يعمل بـ LIKE على body_norm.
 * 'secure-delete' يُفعَّل فقط إن صحّ فعلياً في هذه النسخة من SQLite (معطوب مع المحتوى الخارجي حتى 3.46.0).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || !Schema::hasTable('wa_messages') || !Schema::hasColumn('wa_messages', 'body_norm')) {
            return;
        }

        // تعبئة احتياطية لما فات تعبئة E11 (نص بلا body_norm)، بالقواعد الحالية وإصدارها
        FtsSchema::backfillNorm();
        if (Schema::hasTable('settings')) {
            Setting::set('search_norm_version', (string) TextNormalizer::VERSION);
        }

        if (!FtsSchema::supported()) {
            WaLog::warning('wa.fts.unsupported', ['reason' => FtsSchema::unsupportedReason(), 'code' => FtsSchema::sqliteVersion()]);

            return;
        }
        FtsSchema::install();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            FtsSchema::drop();
        }
    }
};
