<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('is_active')->constrained('accounts')->nullOnDelete();
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('is_active')->constrained('accounts')->nullOnDelete();
        });

        Schema::table('violation_types', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('is_active')->constrained('accounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });

        Schema::table('violation_types', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
