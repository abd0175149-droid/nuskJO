<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قواعد الرد الحتمية (E37): جدول القواعد (نص ثابت يعتمده المالك لأسئلة متكررة، بلا نموذج)، ووضعها في الإعدادات
 * (shadow افتراضياً: تُقيَّم ولا تُرسل)، وتخزين المكوّنات الرسمية في واتساب (commands وice breakers)، وصلاحية
 * whatsapp.auto_rules للمدير. إضافية بحتة ومتكررة الأمان، ولا تعبئة: القواعد الأولى يدخلها المدير بعد اعتماد نصوصها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_auto_rules')) {
            Schema::create('wa_auto_rules', function (Blueprint $t) {
                $t->id();
                $t->string('name', 80);
                $t->boolean('is_active')->default(false);
                // الأصغر يُقيَّم أولاً، ثم المعرّف
                $t->unsignedSmallInteger('priority')->default(100);
                // exact | contains | any_of
                $t->string('match_type', 10);
                // القيم كما كتبها المدير، وبعد التطبيع (تُحسب عند الحفظ)
                $t->json('patterns');
                $t->json('patterns_norm');
                $t->json('conditions')->nullable();
                $t->unsignedTinyInteger('max_words')->default(6);
                $t->text('reply_text_ar');
                $t->text('reply_text_en')->nullable();
                $t->string('reply_kind', 10)->default('text');
                $t->string('tag_topic', 16)->nullable();
                $t->unsignedSmallInteger('cooldown_minutes')->default(360);
                $t->string('command_name', 32)->nullable();
                $t->unsignedInteger('hits')->default(0);
                $t->unsignedInteger('shadow_hits')->default(0);
                $t->timestamp('last_hit_at')->nullable();
                $t->string('last_error', 191)->nullable();
                // آخر معاينة أو محاكاة بعد آخر تعديل: لا تفعيل بلاها
                $t->timestamp('validated_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->index(['is_active', 'priority']);
            });
        }

        if (Schema::hasTable('wa_bot_settings')) {
            if (!Schema::hasColumn('wa_bot_settings', 'auto_rules_mode')) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->string('auto_rules_mode', 8)->default('shadow'));
            }
            if (!Schema::hasColumn('wa_bot_settings', 'conversational_automation')) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->json('conversational_automation')->nullable());
            }
        }

        // إدارة القواعد: للمدير وحده، والمنح لغيره بقرار المالك (لا RolePermissionSeeder على الإنتاج)
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('slug', 'whatsapp.auto_rules')->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => 'whatsapp.auto_rules', 'name' => 'إدارة الردود الحتمية', 'module' => 'whatsapp', 'created_at' => now(), 'updated_at' => now()]);
            if ($admin = DB::table('roles')->where('slug', 'admin')->value('id')) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && ($id = DB::table('permissions')->where('slug', 'whatsapp.auto_rules')->value('id'))) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        foreach (['auto_rules_mode', 'conversational_automation'] as $col) {
            if (Schema::hasColumn('wa_bot_settings', $col)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        Schema::dropIfExists('wa_auto_rules');
    }
};
