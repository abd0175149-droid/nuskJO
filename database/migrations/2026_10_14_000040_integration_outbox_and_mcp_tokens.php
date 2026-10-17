<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * التكاملات الصادرة وخادم MCP للقراءة (E40). إضافية ومتكررة الأمان:
 *  - integration_endpoints: وجهات يضبطها المدير، والسر مشفّر (cast encrypted) ولا يغادر الخادم إلا مرة عند توليده.
 *  - integration_outbox: صف لكل حدث ووجهة يُكتب داخل معاملة الحدث نفسها، فريد بـ (endpoint_id, idempotency_key).
 *  - personal_access_tokens: بمخطط Sanctum نفسه (رمز مخزّن كبصمة sha256 وقدرات وانتهاء)، فالانتقال إلى الحزمة لاحقاً بلا ترحيل.
 *  - صلاحيتا integrations.manage وmcp.use للمدير وحده، بلا RolePermissionSeeder (يمسح المنح المخصصة، A11).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['slug' => 'integrations.manage', 'name' => 'إدارة التكاملات الصادرة', 'module' => 'integrations'],
        ['slug' => 'mcp.use', 'name' => 'رموز خادم MCP للقراءة', 'module' => 'mcp'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('integration_endpoints')) {
            Schema::create('integration_endpoints', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('url', 512);
                $table->json('events');
                $table->boolean('include_amounts')->default(false);
                $table->text('secret');
                $table->text('previous_secret')->nullable();
                $table->timestamp('previous_secret_expires_at')->nullable();
                $table->timestamp('secret_rotated_at')->nullable();
                $table->boolean('is_active')->default(false);
                $table->string('paused_reason', 120)->nullable();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->timestamp('last_ping_ok_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('integration_outbox')) {
            Schema::create('integration_outbox', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('endpoint_id')->index();
                $table->string('event', 48);
                $table->string('idempotency_key', 120);
                $table->json('payload');
                $table->string('status', 12)->default('pending');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                // موعد الوظيفة المجدولة الأخيرة لهذا الصف: المُصالِح لا يجدول ثانية ما دامت لم تتأخر
                $table->timestamp('dispatched_at')->nullable();
                $table->string('last_delivery_id', 36)->nullable();
                $table->unsignedSmallInteger('last_status_code')->nullable();
                $table->string('last_error', 300)->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
                $table->unique(['endpoint_id', 'idempotency_key']);
                $table->index(['status', 'next_attempt_at']);
            });
        }

        if (!Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('permissions')) {
            $admin = Schema::hasTable('roles') ? DB::table('roles')->where('slug', 'admin')->value('id') : null;
            foreach (self::PERMISSIONS as $permission) {
                DB::table('permissions')->insertOrIgnore($permission + ['created_at' => now(), 'updated_at' => now()]);
                $id = DB::table('permissions')->where('slug', $permission['slug'])->value('id');
                if ($id && $admin && Schema::hasTable('role_permissions')) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');
        Schema::dropIfExists('integration_endpoints');
        Schema::dropIfExists('personal_access_tokens');

        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('slug', array_column(self::PERMISSIONS, 'slug'))->pluck('id');
            if (Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            }
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
