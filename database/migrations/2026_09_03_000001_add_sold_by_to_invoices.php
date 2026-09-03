<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // البائع (الموظف الذي تُنسب إليه أرباح الفاتورة). عمود بسيط بلا قيد FK
        // (SQLite لا يدعم إضافة FK عبر ALTER TABLE)؛ السلامة المرجعية عبر exists:employees,id.
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'sold_by')) {
                $table->unsignedBigInteger('sold_by')->nullable()->after('created_by');
                $table->index('sold_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'sold_by')) {
                $table->dropIndex(['sold_by']);
                $table->dropColumn('sold_by');
            }
        });
    }
};
