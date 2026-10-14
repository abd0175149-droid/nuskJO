<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * أمر طوارئ غير مدمّر: يعيد صفة المدير لمستخدم موجود.
 * يُستعمل بدل RolePermissionSeeder الذي يعيد كتابة منح الأدوار المخصصة.
 *
 *   php artisan auth:restore-admin owner@example.com [--reset-password]
 */
class RestoreAdmin extends Command
{
    protected $signature = 'auth:restore-admin {email} {--reset-password : توليد كلمة مرور جديدة تُطبع مرة واحدة}';

    protected $description = 'إعادة دور المدير وتفعيل حساب موجود (طوارئ، دون مسح أي صلاحيات)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (!$user) {
            $this->error('لا يوجد مستخدم بهذا البريد.');

            return self::FAILURE;
        }

        $admin = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'مدير عام', 'description' => 'صلاحيات كاملة']);
        $admin->permissions()->syncWithoutDetaching(Permission::pluck('id'));

        $user->forceFill(['role_id' => $admin->id, 'is_active' => true])->save();

        $password = null;
        if ($this->option('reset-password')) {
            $password = Str::password(16);
            $user->forceFill(['password' => Hash::make($password)])->save();
        }

        AuditLog::create([
            'user_id' => null,
            'user_name' => 'console',
            'action' => 'user.restore_admin',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'entity_label' => $user->name,
            'changes' => ['reset_password' => (bool) $password],
            'ip_address' => null,
            'created_at' => now(),
        ]);

        $this->info("أصبح {$user->email} مديراً نشطاً.");
        if ($password) {
            $this->warn("كلمة المرور الجديدة (لن تظهر مرة أخرى): {$password}");
        }

        return self::SUCCESS;
    }
}
