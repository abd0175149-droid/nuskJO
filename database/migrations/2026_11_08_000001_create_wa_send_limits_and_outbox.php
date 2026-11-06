<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حدود الإرسال الاستباقي وطابوره (2026-11-06، docs/whatsapp/limits.md):
 * - wa_send_limits: سقوف لكل رقم × نوع (الفارغ = الكل)، والنافذة والأيام والفاصل. صف «الكل × الكل» بالقيم المحافظة.
 * - wa_outbox: كل رسالة نظام ومتابعة مجدولة بمفتاح فريد، تُحسم بـ SendGovernor ثم تُرسل عبر WhatsAppChannel.
 * - wa_messages.type_key: نوع رسالة النظام على صفها في السجل. wa_bot_settings.warmup_ladder: سلّم تسخين الأرقام.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_send_limits')) {
            Schema::create('wa_send_limits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('channel_id')->nullable();
                $t->string('type_key', 40)->nullable();
                foreach (['per_minute', 'per_hour', 'per_day', 'cold_per_day', 'per_recipient_day'] as $c) {
                    $t->unsignedInteger($c)->nullable();
                }
                $t->unsignedInteger('min_gap_sec')->nullable();
                $t->unsignedTinyInteger('jitter_pct')->nullable();
                $t->boolean('spread')->nullable();
                $t->unsignedTinyInteger('window_from')->nullable();
                $t->unsignedTinyInteger('window_to')->nullable();
                $t->json('weekdays')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['channel_id', 'type_key']);
            });
            $d = \App\Models\WaSendLimit::DEFAULTS;
            DB::table('wa_send_limits')->insert(['channel_id' => null, 'type_key' => null, 'weekdays' => json_encode($d['weekdays'])]
                + array_diff_key($d, ['weekdays' => 1]) + ['created_at' => now(), 'updated_at' => now()]);
        }

        if (!Schema::hasTable('wa_outbox')) {
            Schema::create('wa_outbox', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('channel_id')->nullable();
                $t->unsignedBigInteger('conversation_id')->nullable()->index();
                $t->unsignedBigInteger('client_id')->nullable()->index();
                $t->string('phone', 32)->nullable();
                $t->string('type_key', 40);
                // system | followup | staff
                $t->string('lane', 12)->default('system');
                $t->json('payload')->nullable();
                $t->unsignedTinyInteger('priority')->default(5);
                $t->timestamp('due_at');
                $t->timestamp('expires_at')->nullable();
                // pending | deferred | sending | sent | failed | expired | cancelled | denied | awaiting_approval
                $t->string('status', 20)->default('pending');
                $t->string('defer_reason', 160)->nullable();
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->timestamp('lease_until')->nullable();
                $t->string('send_key', 120)->unique();
                $t->string('source_type', 20)->nullable();
                $t->unsignedBigInteger('source_id')->nullable();
                $t->boolean('is_test')->default(false);
                $t->boolean('cold')->default(false);
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->unsignedBigInteger('message_id')->nullable();
                $t->string('last_error', 500)->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['status', 'due_at']);
                $t->index(['channel_id', 'type_key', 'sent_at']);
                $t->index(['phone', 'sent_at']);
                $t->index(['source_type', 'source_id']);
            });
        }

        if (!Schema::hasColumn('wa_messages', 'type_key')) {
            Schema::table('wa_messages', function (Blueprint $t) {
                $t->string('type_key', 40)->nullable();
                $t->index(['channel_id', 'type_key', 'sent_at'], 'wa_messages_channel_type_sent_index');
            });
        }
        if (!Schema::hasColumn('wa_bot_settings', 'warmup_ladder')) {
            Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->json('warmup_ladder')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_outbox');
        Schema::dropIfExists('wa_send_limits');
        if (Schema::hasColumn('wa_messages', 'type_key')) {
            Schema::table('wa_messages', function (Blueprint $t) {
                $t->dropIndex('wa_messages_channel_type_sent_index');
                $t->dropColumn('type_key');
            });
        }
        if (Schema::hasColumn('wa_bot_settings', 'warmup_ladder')) {
            Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn('warmup_ladder'));
        }
    }
};
