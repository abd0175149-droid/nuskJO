<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // تعديل جدول الفواتير
        Schema::table('invoices', function (Blueprint $table) {
            // حقول جديدة
            $table->string('client_phone', 20)->nullable()->after('client_id');
            $table->date('trip_date')->nullable()->after('client_phone');
            $table->decimal('total_sell_jod', 15, 3)->default(0)->after('total_jod');
            $table->decimal('total_cost_sar', 15, 2)->default(0)->after('total_sell_jod');
            $table->decimal('total_cost_jod', 15, 3)->default(0)->after('total_cost_sar');
            $table->decimal('discount_jod', 15, 3)->default(0)->after('total_cost_jod');

            // جعل agent_id اختياري (لأن الوكلاء الآن في البنود)
            $table->foreignId('agent_id')->nullable()->change();
        });

        // تعديل جدول بنود الفاتورة
        Schema::table('invoice_items', function (Blueprint $table) {
            // إضافة agent_id لكل بند
            $table->foreignId('agent_id')->nullable()->after('invoice_id');
            // إضافة sell_price_jod لكل بند (إن لم يكن موجوداً بالفعل)
            // sell_price_jod موجود مسبقاً في الجدول
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['client_phone', 'trip_date', 'total_sell_jod', 'total_cost_sar', 'total_cost_jod', 'discount_jod']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('agent_id');
        });
    }
};
