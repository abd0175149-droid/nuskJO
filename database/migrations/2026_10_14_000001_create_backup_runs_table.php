<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * سجل تشغيل النسخ الاحتياطي. الجدول داخل القاعدة نفسها، فاستعادة نسخة قديمة تُرجع سجلاً قديماً؛
 * مصدر الحقيقة ملفات manifest.json في مجلد النسخ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('backup_runs')) {
            return;
        }

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reason', 16);
            $table->string('status', 16)->default('running');
            $table->string('snapshot_name', 64)->nullable();
            $table->unsignedBigInteger('db_bytes')->nullable();
            $table->unsignedBigInteger('media_bytes')->nullable();
            $table->string('manifest_sha256', 64)->nullable();
            $table->string('integrity', 32)->nullable();
            $table->json('row_counts')->nullable();
            $table->unsignedInteger('migrations_count')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->string('archive_name', 128)->nullable();
            $table->unsignedBigInteger('archive_bytes')->nullable();
            $table->string('archive_sha256', 64)->nullable();
            $table->timestamp('pulled_at')->nullable();
            $table->json('drill_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['reason', 'status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
