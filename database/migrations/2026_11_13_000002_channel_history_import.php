<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * استيراد سجل الرقم عند ربطه (2026-11-06، OpenWa\HistoryImport): حالة الاستيراد وتقدّمه، وقائمة المحادثات المنتظرة،
 * ووقت انتهائه. البوابة تقرأ السجل من الهاتف مباشرة (GET /messages/{chatId}/history)، فلا شيء يُستورد بلا هذه الأعمدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_channels', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_channels', 'history_import_state')) {
                $t->text('history_import_state')->nullable();
            }
            if (!Schema::hasColumn('wa_channels', 'history_queue')) {
                $t->longText('history_queue')->nullable();
            }
            if (!Schema::hasColumn('wa_channels', 'history_imported_at')) {
                $t->timestamp('history_imported_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('wa_channels', function (Blueprint $t) {
            foreach (['history_import_state', 'history_queue', 'history_imported_at'] as $col) {
                if (Schema::hasColumn('wa_channels', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
