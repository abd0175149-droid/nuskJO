<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * سياج المحادثات (E04): صلاحية محادثات أرقام الإدارة، وعلَم لاصق عليها، وعمودا هوية رقمنا
 * لسياج أحداث الحساب. إضافات فقط ومتكررة الأمان.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions') && !DB::table('permissions')->where('slug', 'whatsapp.admin_chats')->exists()) {
            $id = DB::table('permissions')->insertGetId([
                'slug' => 'whatsapp.admin_chats', 'name' => 'محادثات أرقام الإدارة', 'module' => 'whatsapp',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($admin = DB::table('roles')->where('slug', 'admin')->value('id')) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
            }
        }

        if (Schema::hasTable('wa_conversations') && !Schema::hasColumn('wa_conversations', 'is_admin_chat')) {
            Schema::table('wa_conversations', function (Blueprint $t) {
                $t->boolean('is_admin_chat')->default(false)->index();
            });
        }
        if (Schema::hasTable('wa_admin_numbers') && Schema::hasColumn('wa_conversations', 'is_admin_chat')) {
            DB::table('wa_conversations')
                ->whereIn('phone', DB::table('wa_admin_numbers')->select('phone'))
                ->update(['is_admin_chat' => true]);
        }

        if (Schema::hasTable('wa_bot_settings')) {
            Schema::table('wa_bot_settings', function (Blueprint $t) {
                if (!Schema::hasColumn('wa_bot_settings', 'wa_waba_id')) {
                    $t->string('wa_waba_id', 32)->nullable();
                }
                if (!Schema::hasColumn('wa_bot_settings', 'wa_display_phone')) {
                    $t->string('wa_display_phone', 32)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wa_conversations', 'is_admin_chat')) {
            Schema::table('wa_conversations', function (Blueprint $t) {
                $t->dropIndex(['is_admin_chat']);
                $t->dropColumn('is_admin_chat');
            });
        }
        foreach (['wa_waba_id', 'wa_display_phone'] as $col) {
            if (Schema::hasColumn('wa_bot_settings', $col)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        $id = DB::table('permissions')->where('slug', 'whatsapp.admin_chats')->value('id');
        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
