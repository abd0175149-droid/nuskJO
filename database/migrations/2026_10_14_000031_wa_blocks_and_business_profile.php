<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الحظر الرسمي وملف الأعمال (E31): جدول wa_blocks بسبب إلزامي، وعمود wa_conversations.blocked_at،
 * وصلاحية whatsapp.block للمدير وحده، وثلاثة إعدادات لملف واتساب للأعمال في صفحة إعدادات الشركة.
 * إضافية بحتة ومتكررة الأمان: جدول جديد وعمود nullable بلا إعادة بناء، وصفوف بـ insertOrIgnore.
 */
return new class extends Migration
{
    /** إعدادات الشركة الجديدة: المفتاح => [النوع، الوصف كما يظهر في الصفحة] */
    private const SETTINGS = [
        'company_website' => ['string', 'موقع الشركة الإلكتروني (https://…)'],
        'wa_profile_about' => ['text', 'نبذة واتساب للأعمال (حتى 139 حرفاً، بلا تنسيق)'],
        'wa_profile_description' => ['text', 'وصف ملف واتساب للأعمال (حتى 512 حرفاً)'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('wa_blocks')) {
            Schema::create('wa_blocks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->nullable()->index();
                // الرقم الموحّد إن عُرف، أو المعرّف (BSUID) لمن لا رقم له
                $t->string('phone', 32)->nullable()->index();
                $t->string('wa_user_id', 160)->nullable()->index();
                // بعد «حذف بياناتي»: بصمة الرقم أو المعرّف بدلهما، فيبقى الحظر قابلاً للمطابقة مع قائمة ميتا
                $t->string('subject_hash', 64)->nullable()->index();
                $t->string('reason_code', 12);
                $t->string('reason', 300);
                $t->unsignedBigInteger('blocked_by')->nullable();
                // dashboard | admin_number | meta_sync
                $t->string('blocked_via', 12)->default('dashboard');
                $t->unsignedBigInteger('admin_number_id')->nullable();
                $t->timestamp('blocked_at')->nullable();
                $t->unsignedBigInteger('unblocked_by')->nullable();
                $t->timestamp('unblocked_at')->nullable()->index();
                $t->string('unblock_reason', 300)->nullable();
                // blocked | unblocked | not_found | mismatch
                $t->string('meta_state', 12)->default('blocked');
                $t->string('meta_error_code', 16)->nullable();
                $t->timestamp('meta_checked_at')->nullable();
                $t->timestamp('erased_at')->nullable();
                $t->timestamps();
            });
        }

        // ALTER ADD COLUMN وحده: لا FTS على هذا الجدول
        if (Schema::hasTable('wa_conversations') && !Schema::hasColumn('wa_conversations', 'blocked_at')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->timestamp('blocked_at')->nullable());
        }
        if (Schema::hasColumn('wa_conversations', 'blocked_at') && !Schema::hasIndex('wa_conversations', 'wa_conversations_blocked_at_index')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->index('blocked_at'));
        }

        // الحظر للمدير وحده، والمنح لغيره بسكربت موجّه بعد قرار المالك (لا RolePermissionSeeder على الإنتاج)
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('slug', 'whatsapp.block')->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => 'whatsapp.block', 'name' => 'حظر جهات اتصال واتساب', 'module' => 'whatsapp', 'created_at' => now(), 'updated_at' => now()]);
            if ($admin = DB::table('roles')->where('slug', 'admin')->value('id')) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
            }
        }

        // صفوف فارغة تظهر في «بيانات الشركة»؛ القيم يكتبها المدير (SettingController يحدّث الصفوف الموجودة وحدها)
        if (Schema::hasTable('settings')) {
            foreach (self::SETTINGS as $key => [$type, $description]) {
                DB::table('settings')->insertOrIgnore([
                    'key' => $key, 'value' => null, 'group_name' => 'company', 'type' => $type, 'description' => $description, 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->whereIn('key', array_keys(self::SETTINGS))->delete();
        }
        if (Schema::hasTable('permissions') && ($id = DB::table('permissions')->where('slug', 'whatsapp.block')->value('id'))) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        if (Schema::hasIndex('wa_conversations', 'wa_conversations_blocked_at_index')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropIndex('wa_conversations_blocked_at_index'));
        }
        if (Schema::hasColumn('wa_conversations', 'blocked_at')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropColumn('blocked_at'));
        }
        Schema::dropIfExists('wa_blocks');
    }
};
