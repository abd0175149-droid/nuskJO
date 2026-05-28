<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('receipts', 'bank_commission')) {
            Schema::table('receipts', function (Blueprint $table) {
                $table->decimal('bank_commission', 15, 3)->default(0);
            });
        }

        // إضافة حساب عمولات بنكية في شجرة الحسابات
        $parent = \App\Models\Account::where('code', '5000')->first();
        \App\Models\Account::firstOrCreate(
            ['code' => '5500'],
            [
                'name' => 'عمولات بنكية',
                'type' => 'expense',
                'parent_id' => $parent?->id,
                'is_system' => true,
                'currency' => 'JOD',
                'description' => 'عمولات البنوك على التحويلات وسندات القبض',
            ]
        );
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('bank_commission');
        });
    }
};
