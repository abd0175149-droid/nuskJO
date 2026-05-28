<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'transfers',
        'receipts',
        'expenses',
        'violations',
        'invoices',
    ];

    public function up(): void
    {
        // SQLite لا يدعم ALTER TABLE ADD COLUMN مع FOREIGN KEY
        // لذلك نضيف الأعمدة بدون foreign key constraint
        foreach ($this->tables as $table) {
            if (!Schema::hasColumn($table, 'modified_by')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('modified_by')->nullable();
                    $t->datetime('modified_at')->nullable();
                    $t->text('original_values')->nullable();
                });
            }
        }

        // إضافة صلاحيات التعديل بعد الاعتماد
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

        // منح الصلاحيات لدور المدير العام (admin)
        $adminRole = \App\Models\Role::where('slug', 'admin')->first();
        if ($adminRole) {
            $permIds = \App\Models\Permission::whereIn('slug', array_column($permissions, 'slug'))->pluck('id');
            $adminRole->permissions()->syncWithoutDetaching($permIds);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['modified_by', 'modified_at', 'original_values']);
            });
        }

        \App\Models\Permission::whereIn('slug', [
            'transfers.edit_approved', 'invoices.edit_approved',
            'receipts.edit_approved', 'violations.edit_approved', 'expenses.edit_approved',
        ])->delete();
    }
};
