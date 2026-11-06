<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أرقام واتساب على بوابة OpenWA (2026-11-06، docs/whatsapp/openwa.md):
 * - wa_channels: صف لكل رقم = جلسة على البوابة، بمفتاح API محصور بجلستها وسر توقيع webhook خاص بها (مشفّران).
 *   uuid في مسار الـ webhook (api/openwa/webhook/{uuid})، فلا يُعرف الرقم من عنوانه.
 * - wa_channel_users: من يخدم الرقم من الموظفين (الرؤية في الصندوق، TopicRouter).
 * - wa_channel_events: سجل الجلسة (qr، الربط، الانقطاع، العودة، إعادة التشغيل، علامة الحظر).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_channels')) {
            Schema::create('wa_channels', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->string('name', 60);
                // أرقام دولية بلا + بعد الربط (من session.authenticated)
                $t->string('phone', 32)->nullable();
                $t->string('push_name', 120)->nullable();
                $t->string('color', 9)->default('#b8800f');
                $t->string('openwa_session_id', 64)->nullable()->unique();
                $t->text('api_key')->nullable();
                $t->text('webhook_secret')->nullable();
                $t->string('webhook_id', 64)->nullable();
                // created | initializing | qr_ready | authenticating | ready | disconnected | action_required | failed
                $t->string('status', 24)->default('created');
                $t->timestamp('status_at')->nullable();
                $t->timestamp('last_probe_ok_at')->nullable();
                $t->unsignedSmallInteger('probe_failures')->default(0);
                $t->timestamp('last_restart_at')->nullable();
                $t->timestamp('last_inbound_at')->nullable();
                // أدوار الرقم: sales | support | notifications | admin
                $t->json('roles')->nullable();
                // full | limited | off (المرحلة 4)
                $t->string('bot_mode', 10)->default('full');
                // الرقم الافتراضي لمجموعات رسائل النظام: ["invoice","trip",...]
                $t->json('is_default_for')->nullable();
                $t->timestamp('warmup_started_at')->nullable();
                $t->timestamp('paused_until')->nullable();
                $t->string('pause_reason', 160)->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('wa_channel_users')) {
            Schema::create('wa_channel_users', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('channel_id');
                $t->unsignedBigInteger('user_id')->index();
                $t->boolean('is_primary')->default(false);
                $t->timestamps();
                $t->unique(['channel_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('wa_channel_events')) {
            Schema::create('wa_channel_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('channel_id');
                // qr | authenticated | ready | disconnected | reconnect_loop | restriction | restart | probe_failed | probe_ok | logout | created
                $t->string('event', 32);
                $t->json('detail')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['channel_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_channel_events');
        Schema::dropIfExists('wa_channel_users');
        Schema::dropIfExists('wa_channels');
    }
};
