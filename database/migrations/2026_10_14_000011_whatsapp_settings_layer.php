<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * طبقة إعدادات الواتساب (E10): رمز نسخة للحفظ الجزئي (409 عند التعارض)، وهاش رمز التحقق، وتواريخ تدوير
 * الأسرار، ومن أوقف الإرسال ولماذا، وأعلام الميزات. إضافية بحتة ومتكررة الأمان: الكود القديم يتجاهلها.
 * النص الصريح لرمز التحقق لا يُمسح هنا (يمسحه whatsapp:settings-finalize بعد الاستقرار)، فيبقى التراجع ممكناً.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'settings_version', 'wa_verify_token_hash',
        'wa_verify_token_rotated_at', 'wa_token_rotated_at', 'wa_app_secret_rotated_at', 'api_key_rotated_at',
        'wa_suspended_at', 'wa_suspended_by', 'wa_suspended_source', 'wa_suspended_reason',
        'feature_flags',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('wa_bot_settings')) {
            return;
        }

        Schema::table('wa_bot_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('wa_bot_settings', 'settings_version')) {
                $t->unsignedInteger('settings_version')->default(1);
            }
            if (!Schema::hasColumn('wa_bot_settings', 'wa_verify_token_hash')) {
                $t->string('wa_verify_token_hash', 64)->nullable();
            }
            foreach (['wa_verify_token_rotated_at', 'wa_token_rotated_at', 'wa_app_secret_rotated_at', 'api_key_rotated_at', 'wa_suspended_at'] as $column) {
                if (!Schema::hasColumn('wa_bot_settings', $column)) {
                    $t->timestamp($column)->nullable();
                }
            }
            if (!Schema::hasColumn('wa_bot_settings', 'wa_suspended_by')) {
                $t->unsignedBigInteger('wa_suspended_by')->nullable();
            }
            if (!Schema::hasColumn('wa_bot_settings', 'wa_suspended_source')) {
                $t->string('wa_suspended_source', 20)->nullable();   // manual | meta_event | graph_error | restore
            }
            if (!Schema::hasColumn('wa_bot_settings', 'wa_suspended_reason')) {
                $t->string('wa_suspended_reason', 500)->nullable();
            }
            if (!Schema::hasColumn('wa_bot_settings', 'feature_flags')) {
                $t->json('feature_flags')->nullable();
            }
        });

        // هاش الرمز الحالي، فتتحقق المصافحة به من أول يوم
        foreach (DB::table('wa_bot_settings')->whereNotNull('wa_verify_token')->whereNull('wa_verify_token_hash')->get(['id', 'wa_verify_token']) as $row) {
            if ((string) $row->wa_verify_token !== '') {
                DB::table('wa_bot_settings')->where('id', $row->id)->update(['wa_verify_token_hash' => hash('sha256', (string) $row->wa_verify_token)]);
            }
        }

        // صلاحية إيقاف الإرسال وحده (لا استئنافه)؛ لا تُمنح لأحد هنا (المدير يملك كل شيء)، ومنحها قرار المالك
        if (Schema::hasTable('permissions') && !DB::table('permissions')->where('slug', 'whatsapp.kill_switch')->exists()) {
            DB::table('permissions')->insert(['slug' => 'whatsapp.kill_switch', 'name' => 'إيقاف الإرسال الطارئ', 'module' => 'whatsapp', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('slug', 'whatsapp.kill_switch')->value('id');
            if ($id) {
                DB::table('role_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('wa_bot_settings', $column)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
