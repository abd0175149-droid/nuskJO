<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('unit_price_jod', 15, 3)->default(0)->after('unit_price_sar');
            $table->decimal('total_cost_jod', 15, 3)->default(0)->after('total_cost_sar');
            $table->text('statement')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['unit_price_jod', 'total_cost_jod', 'statement']);
        });
    }
};
