<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول الفترات المحاسبية (لمنع التسجيل في فترات مقفلة)
        if (!Schema::hasTable('accounting_periods')) {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->integer('month'); // 1-12, or 0 for full year
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closing_entry_number')->nullable();
            $table->timestamps();
            $table->unique(['year', 'month']);
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
        });
        }

        // ضمان عدم تكرار أرقام القيود (قد يكون موجوداً)
        Schema::table('journal_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('journal_entries', 'reversal_of')) {
                $table->string('reversal_of')->nullable()->after('reference_id');
            }
            if (!Schema::hasColumn('journal_entries', 'is_reversed')) {
                $table->boolean('is_reversed')->default(false)->after('reversal_of');
            }
        });

        // unique constraint — skip if exists
        try {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->unique('entry_number');
            });
        } catch (\Exception $e) {
            // index already exists
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(['entry_number']);
            $table->dropColumn(['reversal_of', 'is_reversed']);
        });
    }
};
