<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * متابعة الصندوق والردود الجاهزة (E17): إغلاق المحادثة وتأجيلها و«غير مقروء» الداخلي، والتثبيت الشخصي،
 * والردود الجاهزة، ومفتاح منع تكرار طلبات التسعير، وصلاحية whatsapp.quick_replies للمدير.
 * إضافية بحتة ومتكررة الأمان: أعمدة nullable وجداول جديدة وفهارس، بلا إعادة بناء لأي جدول.
 */
return new class extends Migration
{
    private const CONVERSATION_COLUMNS = [
        'closed_at' => 'timestamp',
        'closed_by' => 'unsignedBigInteger',
        'snoozed_until' => 'timestamp',
        'snoozed_by' => 'unsignedBigInteger',
        'marked_unread_at' => 'timestamp',
        'marked_unread_by' => 'unsignedBigInteger',
    ];

    public function up(): void
    {
        if (Schema::hasTable('wa_conversations')) {
            foreach (self::CONVERSATION_COLUMNS as $col => $type) {
                if (!Schema::hasColumn('wa_conversations', $col)) {
                    Schema::table('wa_conversations', fn (Blueprint $t) => $t->{$type}($col)->nullable());
                }
            }
            if (!Schema::hasIndex('wa_conversations', 'wa_conversations_status_index')) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $t->index('status'));
            }
            if (!Schema::hasIndex('wa_conversations', 'wa_conversations_snoozed_until_index')) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $t->index('snoozed_until'));
            }
        }

        if (!Schema::hasTable('wa_conversation_pins')) {
            Schema::create('wa_conversation_pins', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('conversation_id')->index();
                $t->timestamp('created_at')->nullable();
                $t->unique(['user_id', 'conversation_id']);
            });
        }

        if (!Schema::hasTable('wa_quick_replies')) {
            Schema::create('wa_quick_replies', function (Blueprint $t) {
                $t->id();
                $t->string('title', 80);
                $t->text('body');
                $t->string('category', 24)->default('general');
                $t->boolean('is_active')->default(false);
                // نص يذكر بيانات دفع: لا يُفعَّل قبل اعتماد من يملك whatsapp.settings
                $t->boolean('needs_approval')->default(false);
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        // إضافة فقط على الجدول المالي: الطلبات القديمة dedupe_key فيها NULL، وSQLite يقبل أكثر من NULL في الفهرس الفريد
        if (Schema::hasTable('quote_requests')) {
            if (!Schema::hasColumn('quote_requests', 'source_msg_id')) {
                Schema::table('quote_requests', fn (Blueprint $t) => $t->unsignedBigInteger('source_msg_id')->nullable());
            }
            if (!Schema::hasColumn('quote_requests', 'dedupe_key')) {
                Schema::table('quote_requests', fn (Blueprint $t) => $t->string('dedupe_key', 40)->nullable());
            }
            if (!Schema::hasIndex('quote_requests', 'quote_requests_conv_dedupe_unique')) {
                Schema::table('quote_requests', fn (Blueprint $t) => $t->unique(['conversation_id', 'dedupe_key'], 'quote_requests_conv_dedupe_unique'));
            }
        }

        // إدارة الردود الجاهزة: للمدير وحده، والمنح لغيره بقرار المالك (لا RolePermissionSeeder على الإنتاج)
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('slug', 'whatsapp.quick_replies')->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => 'whatsapp.quick_replies', 'name' => 'إدارة الردود الجاهزة', 'module' => 'whatsapp', 'created_at' => now(), 'updated_at' => now()]);
            if ($admin = DB::table('roles')->where('slug', 'admin')->value('id')) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && ($id = DB::table('permissions')->where('slug', 'whatsapp.quick_replies')->value('id'))) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        if (Schema::hasIndex('quote_requests', 'quote_requests_conv_dedupe_unique')) {
            Schema::table('quote_requests', fn (Blueprint $t) => $t->dropUnique('quote_requests_conv_dedupe_unique'));
        }
        foreach (['source_msg_id', 'dedupe_key'] as $col) {
            if (Schema::hasColumn('quote_requests', $col)) {
                Schema::table('quote_requests', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        Schema::dropIfExists('wa_quick_replies');
        Schema::dropIfExists('wa_conversation_pins');
        foreach (['wa_conversations_status_index', 'wa_conversations_snoozed_until_index'] as $index) {
            if (Schema::hasIndex('wa_conversations', $index)) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
        foreach (array_keys(self::CONVERSATION_COLUMNS) as $col) {
            if (Schema::hasColumn('wa_conversations', $col)) {
                Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
