<?php

use App\Services\WhatsApp\Search\FtsSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * المراقبة التشغيلية والتحليلات (E24): إضافات فقط ومحروسة، فلا يُعاد بناء جدول (لا ->change()).
 *
 * - wa_messages: كائن pricing من ويبهوك الحالة (أول ظهور هو المعتمد)، وفهرسان للتحليلات والعدّاد اليومي.
 *   صفحة الإخفاقات تستعمل فهرس E18 القائم (direction, status, created_at) فلا يُكرَّر.
 * - wa_bot_usage: فهرس (source, created_at) لتجميع الكلفة بالفترة.
 * - wa_bot_settings: حد تنبيه الصادر اليومي، وسقف الإرسال المبادر، وسعر رسالة الخدمة (اختياري، للتقدير).
 * - الصلاحية quotes.export للمدير وحده (A11: لا RolePermissionSeeder على الإنتاج؛ منحها لغيره بقرار المالك).
 *
 * إنشاء الفهارس يقفل الكتابة على wa_messages لحظياً: يُنفَّذ في ساعة هادئة (الويبهوك يرد 503 عند القفل فتعيد ميتا).
 */
return new class extends Migration
{
    private const CREATED = 'wa_messages_created_at_index';

    private const DIR_SRC = 'wa_messages_dir_src_created_index';

    private const USAGE = 'wa_bot_usage_source_created_index';

    public function up(): void
    {
        if (Schema::hasTable('wa_messages')) {
            Schema::table('wa_messages', function (Blueprint $t) {
                if (!Schema::hasColumn('wa_messages', 'pricing_billable')) {
                    $t->boolean('pricing_billable')->nullable();
                }
                if (!Schema::hasColumn('wa_messages', 'pricing_model')) {
                    $t->string('pricing_model', 16)->nullable();
                }
                if (!Schema::hasColumn('wa_messages', 'pricing_category')) {
                    $t->string('pricing_category', 32)->nullable();
                }
                if (!Schema::hasColumn('wa_messages', 'pricing_type')) {
                    $t->string('pricing_type', 32)->nullable();
                }
            });
            if (!Schema::hasIndex('wa_messages', self::CREATED)) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->index('created_at', self::CREATED));
            }
            if (!Schema::hasIndex('wa_messages', self::DIR_SRC)) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->index(['direction', 'source', 'created_at'], self::DIR_SRC));
            }
        }

        if (Schema::hasTable('wa_bot_usage') && !Schema::hasIndex('wa_bot_usage', self::USAGE)) {
            Schema::table('wa_bot_usage', fn (Blueprint $t) => $t->index(['source', 'created_at'], self::USAGE));
        }

        if (Schema::hasTable('wa_bot_settings')) {
            Schema::table('wa_bot_settings', function (Blueprint $t) {
                if (!Schema::hasColumn('wa_bot_settings', 'wa_daily_alert_threshold')) {
                    $t->unsignedInteger('wa_daily_alert_threshold')->default(300);
                }
                if (!Schema::hasColumn('wa_bot_settings', 'wa_system_daily_cap')) {
                    $t->unsignedInteger('wa_system_daily_cap')->default(50);
                }
                if (!Schema::hasColumn('wa_bot_settings', 'wa_service_rate_usd')) {
                    $t->decimal('wa_service_rate_usd', 8, 5)->nullable();
                }
            });
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->insertOrIgnore([
                'slug' => 'quotes.export', 'name' => 'تصدير طلبات التسعير', 'module' => 'quotes',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = DB::table('permissions')->where('slug', 'quotes.export')->value('id');
            if ($id && ($admin = DB::table('roles')->where('slug', 'admin')->value('id'))) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        foreach ([self::CREATED, self::DIR_SRC] as $index) {
            if (Schema::hasTable('wa_messages') && Schema::hasIndex('wa_messages', $index)) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
        if (Schema::hasTable('wa_bot_usage') && Schema::hasIndex('wa_bot_usage', self::USAGE)) {
            Schema::table('wa_bot_usage', fn (Blueprint $t) => $t->dropIndex(self::USAGE));
        }
        foreach (['pricing_billable', 'pricing_model', 'pricing_category', 'pricing_type'] as $column) {
            if (Schema::hasColumn('wa_messages', $column)) {
                Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        // SQLite أقدم من 3.35 يعيد بناء الجدول عند حذف عمود فيُسقط triggers فهرس البحث (E30): تُعاد إن نقصت،
        // وما دام الفهرس نفسه قائماً (التراجع الكامل يحذفه قبل هذه الهجرة عمداً)
        $fts = FtsSchema::class;
        if (class_exists($fts) && Schema::hasTable($fts::TABLE) && $fts::supported() && $fts::missingTriggers() !== []) {
            $fts::repair();
        }
        foreach (['wa_daily_alert_threshold', 'wa_system_daily_cap', 'wa_service_rate_usd'] as $column) {
            if (Schema::hasColumn('wa_bot_settings', $column)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        if (Schema::hasTable('permissions') && ($id = DB::table('permissions')->where('slug', 'quotes.export')->value('id'))) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
