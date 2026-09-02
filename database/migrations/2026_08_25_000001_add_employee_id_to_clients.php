<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // عمود بسيط بلا قيد FK (SQLite لا يدعم إضافة FK عبر ALTER TABLE)؛
        // السلامة المرجعية مضمونة عبر التحقق exists:employees,id في المتحكمات.
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('agent_id');
                $table->index('employee_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'employee_id')) {
                $table->dropIndex(['employee_id']);
                $table->dropColumn('employee_id');
            }
        });
    }
};
