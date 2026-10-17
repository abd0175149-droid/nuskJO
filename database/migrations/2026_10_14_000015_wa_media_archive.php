<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أرشيف مرفقات العملاء (E14): حالة الأرشفة وخطؤها ووقتها لكل رسالة، وإشعار الحفظ وموافقة العميل لكل محادثة،
 * وصلاحية whatsapp.media للمدير. إضافية بحتة ومتكررة الأمان (ADD COLUMN فقط)، وأعمدة E11 تُضاف إن غابت.
 */
return new class extends Migration
{
    /** أعمدة E11 بالتعريفات نفسها (2026_10_14_000012) */
    private const E11_MEDIA = [
        'media_id' => ['string', 128],
        'media_mime' => ['string', 100],
        'media_sha256' => ['string', 100],
        'media_filename' => ['string', 255],
        'media_size' => ['unsignedInteger', null],
        'media_path' => ['string', 255],
    ];

    public function up(): void
    {
        if (Schema::hasTable('wa_messages')) {
            foreach (self::E11_MEDIA as $col => [$type, $len]) {
                if (!Schema::hasColumn('wa_messages', $col)) {
                    Schema::table('wa_messages', fn (Blueprint $t) => $len ? $t->{$type}($col, $len)->nullable() : $t->{$type}($col)->nullable());
                }
            }
            if (!Schema::hasColumn('wa_messages', 'media_status')) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->string('media_status', 16)->nullable()->index());
            }
            if (!Schema::hasColumn('wa_messages', 'media_error')) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->string('media_error', 32)->nullable());
            }
            if (!Schema::hasColumn('wa_messages', 'media_archived_at')) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->timestamp('media_archived_at')->nullable()->index());
            }
        }

        if (Schema::hasTable('wa_conversations')) {
            if (!Schema::hasColumn('wa_conversations', 'media_consent')) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $t->string('media_consent', 10)->nullable());
            }
            foreach (['media_consent_at', 'media_notice_at'] as $col) {
                if (!Schema::hasColumn('wa_conversations', $col)) {
                    Schema::table('wa_conversations', fn (Blueprint $t) => $t->timestamp($col)->nullable());
                }
            }
        }

        // عرض المرفقات وتنزيلها: للمدير وحده، والمنح لغيره من صفحة الأدوار بقرار المالك (A11)
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('slug', 'whatsapp.media')->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => 'whatsapp.media', 'name' => 'عرض وتنزيل مرفقات العملاء', 'module' => 'whatsapp', 'created_at' => now(), 'updated_at' => now()]);
            if ($admin = DB::table('roles')->where('slug', 'admin')->value('id')) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && ($id = DB::table('permissions')->where('slug', 'whatsapp.media')->value('id'))) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        foreach (['media_consent', 'media_consent_at', 'media_notice_at'] as $col) {
            if (Schema::hasColumn('wa_conversations', $col)) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        foreach (['media_status', 'media_archived_at'] as $col) {
            if (Schema::hasIndex('wa_messages', "wa_messages_{$col}_index")) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->dropIndex("wa_messages_{$col}_index"));
            }
        }
        foreach (['media_status', 'media_error', 'media_archived_at'] as $col) {
            if (Schema::hasColumn('wa_messages', $col)) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
