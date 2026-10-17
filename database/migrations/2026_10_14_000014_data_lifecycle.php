<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دورة حياة البيانات (E13): سجل طلبات الحذف (بصمة الرقم لا الرقم، وأعداد فقط)، وعمود erased_at وفهرس
 * التقليم في wa_conversations، والتجميع الشهري لاستهلاك البوت قبل تقليم خامه، وصلاحية whatsapp.erase للمدير.
 * إضافية بحتة ومتكررة الأمان: لا ->change() ولا إعادة بناء جدول.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_erasure_requests')) {
            Schema::create('wa_erasure_requests', function (Blueprint $t) {
                $t->id();
                $t->string('status', 16)->default('received');   // received|verified|running|done|rejected|cancelled
                $t->string('channel', 12);                        // whatsapp|email|staff
                $t->unsignedBigInteger('conversation_id')->nullable()->index();
                $t->string('subject_hmac', 64)->index();
                $t->string('wa_user_hmac', 64)->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamp('due_at')->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->unsignedBigInteger('verified_by')->nullable();
                $t->string('verification_method', 32)->nullable();
                $t->timestamp('executed_at')->nullable();
                $t->string('executed_by', 64)->nullable();
                $t->json('counts')->nullable();
                $t->string('reason_code', 32)->nullable();
                $t->timestamps();
            });
        }

        if (Schema::hasTable('wa_conversations')) {
            if (!Schema::hasColumn('wa_conversations', 'erased_at')) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $t->timestamp('erased_at')->nullable());
            }
            if (!Schema::hasIndex('wa_conversations', 'wa_conversations_status_last_message_at_index')) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $t->index(['status', 'last_message_at']));
            }
        }

        if (!Schema::hasTable('wa_bot_usage_monthly')) {
            Schema::create('wa_bot_usage_monthly', function (Blueprint $t) {
                $t->id();
                $t->string('month', 7);
                $t->string('source', 12);
                $t->string('model', 80);
                $t->unsignedInteger('replies')->default(0);
                $t->unsignedInteger('calls')->default(0);
                $t->unsignedBigInteger('prompt_tokens')->default(0);
                $t->unsignedBigInteger('output_tokens')->default(0);
                $t->unsignedBigInteger('cache_read_tokens')->default(0);
                $t->unsignedBigInteger('cache_write_tokens')->default(0);
                $t->unsignedInteger('leaked_count')->default(0);
                $t->unsignedBigInteger('latency_sum_ms')->default(0);
                $t->unsignedInteger('latency_n')->default(0);
                $t->timestamps();
                $t->unique(['month', 'source', 'model']);
            });
        }

        // صلاحية الصفحة والتحقق والرفض؛ التنفيذ من سطر الأوامر وحده. للمدير، والمنح لغيره قرار المالك (A11)
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('slug', 'whatsapp.erase')->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => 'whatsapp.erase', 'name' => 'طلبات حذف البيانات', 'module' => 'whatsapp', 'created_at' => now(), 'updated_at' => now()]);
            if ($admin = DB::table('roles')->where('slug', 'admin')->value('id')) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && ($id = DB::table('permissions')->where('slug', 'whatsapp.erase')->value('id'))) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        Schema::dropIfExists('wa_bot_usage_monthly');
        Schema::dropIfExists('wa_erasure_requests');
        if (Schema::hasIndex('wa_conversations', 'wa_conversations_status_last_message_at_index')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropIndex('wa_conversations_status_last_message_at_index'));
        }
        if (Schema::hasColumn('wa_conversations', 'erased_at')) {
            Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropColumn('erased_at'));
        }
    }
};
