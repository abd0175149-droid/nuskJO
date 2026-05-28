<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إعادة إضافة الأعمدة المفقودة بسبب فشل Migration سابقة على SQLite
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = ['transfers', 'receipts', 'expenses', 'violations', 'invoices'];

        foreach ($tables as $table) {
            if (!Schema::hasColumn($table, 'modified_by')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('modified_by')->nullable();
                });
            }
            if (!Schema::hasColumn($table, 'modified_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->datetime('modified_at')->nullable();
                });
            }
            if (!Schema::hasColumn($table, 'original_values')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->text('original_values')->nullable();
                });
            }
        }

        // أعمدة الحوالات
        if (!Schema::hasColumn('transfers', 'difference_amount')) {
            Schema::table('transfers', function (Blueprint $t) {
                $t->decimal('difference_amount', 15, 3)->default(0);
            });
        }
        if (!Schema::hasColumn('transfers', 'difference_type')) {
            Schema::table('transfers', function (Blueprint $t) {
                $t->string('difference_type', 20)->nullable();
            });
        }
        if (!Schema::hasColumn('transfers', 'expense_id')) {
            Schema::table('transfers', function (Blueprint $t) {
                $t->unsignedBigInteger('expense_id')->nullable();
            });
        }
        if (!Schema::hasColumn('transfers', 'expense_category_id')) {
            Schema::table('transfers', function (Blueprint $t) {
                $t->unsignedBigInteger('expense_category_id')->nullable();
            });
        }
        if (!Schema::hasColumn('transfers', 'revenue_account_id')) {
            Schema::table('transfers', function (Blueprint $t) {
                $t->unsignedBigInteger('revenue_account_id')->nullable();
            });
        }

        // إضافة الصلاحيات
        $permissions = [
            ['name' => 'تعديل حوالة معتمدة', 'slug' => 'transfers.edit_approved', 'module' => 'transfers'],
            ['name' => 'تعديل فاتورة معتمدة', 'slug' => 'invoices.edit_approved', 'module' => 'invoices'],
            ['name' => 'تعديل سند قبض معتمد', 'slug' => 'receipts.edit_approved', 'module' => 'receipts'],
            ['name' => 'تعديل مخالفة معتمدة', 'slug' => 'violations.edit_approved', 'module' => 'violations'],
            ['name' => 'تعديل مصروف معتمد', 'slug' => 'expenses.edit_approved', 'module' => 'expenses'],
        ];
        foreach ($permissions as $p) {
            \App\Models\Permission::firstOrCreate(['slug' => $p['slug']], $p);
        }
        $adminRole = \App\Models\Role::where('slug', 'admin')->first();
        if ($adminRole) {
            $permIds = \App\Models\Permission::whereIn('slug', array_column($permissions, 'slug'))->pluck('id');
            $adminRole->permissions()->syncWithoutDetaching($permIds);
        }
    }

    public function down(): void
    {
        // لا حاجة — الأعمدة تُحذف من الـ migrations الأساسية
    }
};
