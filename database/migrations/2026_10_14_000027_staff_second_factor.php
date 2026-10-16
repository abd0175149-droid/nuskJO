<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العامل الثاني للموظفين (E27): سر TOTP مشفّر ورموز الاسترداد مجزّأة وآخر خطوة قُبلت (منع إعادة الاستعمال)،
 * وآخر نشاط (يُنسخ من جدول الجلسات كل 15 دقيقة) وآخر عنوان دخول، وشرط العامل الثاني لرقم الإدارة.
 * إضافية بحتة ومتكررة الأمان: أعمدة nullable أو بقيمة افتراضية، بلا إعادة بناء ولا لمس لبيانات قائمة.
 */
return new class extends Migration
{
    private const USER_COLUMNS = [
        'two_factor_secret' => 'text',
        'two_factor_recovery_codes' => 'text',
        'two_factor_confirmed_at' => 'timestamp',
        'two_factor_last_step' => 'unsignedBigInteger',
        'last_seen_at' => 'timestamp',
    ];

    public function up(): void
    {
        if (Schema::hasTable('users')) {
            foreach (self::USER_COLUMNS as $col => $type) {
                if (!Schema::hasColumn('users', $col)) {
                    Schema::table('users', fn (Blueprint $t) => $t->{$type}($col)->nullable());
                }
            }
            if (!Schema::hasColumn('users', 'last_login_ip')) {
                Schema::table('users', fn (Blueprint $t) => $t->string('last_login_ip', 45)->nullable());
            }
            if (!Schema::hasIndex('users', 'users_last_seen_at_index')) {
                Schema::table('users', fn (Blueprint $t) => $t->index('last_seen_at'));
            }
        }

        if (Schema::hasTable('wa_admin_numbers') && !Schema::hasColumn('wa_admin_numbers', 'require_mfa')) {
            Schema::table('wa_admin_numbers', fn (Blueprint $t) => $t->boolean('require_mfa')->default(false));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wa_admin_numbers') && Schema::hasColumn('wa_admin_numbers', 'require_mfa')) {
            Schema::table('wa_admin_numbers', fn (Blueprint $t) => $t->dropColumn('require_mfa'));
        }
        if (!Schema::hasTable('users')) {
            return;
        }
        if (Schema::hasIndex('users', 'users_last_seen_at_index')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropIndex('users_last_seen_at_index'));
        }
        foreach ([...array_keys(self::USER_COLUMNS), 'last_login_ip'] as $col) {
            if (Schema::hasColumn('users', $col)) {
                Schema::table('users', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
