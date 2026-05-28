<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('transfers', 'difference_amount')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->decimal('difference_amount', 15, 3)->default(0);
                $table->string('difference_type', 20)->nullable();
                $table->unsignedBigInteger('expense_id')->nullable();
                $table->unsignedBigInteger('expense_category_id')->nullable();
                $table->unsignedBigInteger('revenue_account_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn([
                'difference_amount', 'difference_type',
                'expense_id', 'expense_category_id', 'revenue_account_id',
            ]);
        });
    }
};
