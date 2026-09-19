<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كل جداول نظام بوت الواتساب في ملف واحد (يبسّط النشر عبر docker cp).
 * أعمدة الربط بلا قيد FK — SQLite لا يدعم إضافتها عبر ALTER، والسلامة عبر التحقق في الكود.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ========== المحادثات ==========
        if (!Schema::hasTable('wa_conversations')) {
            Schema::create('wa_conversations', function (Blueprint $t) {
                $t->id();
                $t->string('phone', 32)->unique();       // أرقام مجرّدة للمطابقة
                $t->string('wa_phone', 32);              // صيغة الإرسال لميتا
                $t->string('display_name', 120)->nullable();
                $t->unsignedBigInteger('client_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();   // إن كان المرسل موظفاً
                $t->boolean('bot_enabled')->default(true);
                $t->timestamp('bot_paused_until')->nullable();
                $t->timestamp('last_inbound_at')->nullable();   // مرساة نافذة 24 ساعة
                $t->timestamp('last_outbound_at')->nullable();
                $t->timestamp('last_message_at')->nullable();
                $t->string('last_message_preview', 255)->nullable();
                $t->unsignedInteger('unread_count')->default(0);
                $t->boolean('needs_attention')->default(false);
                $t->unsignedBigInteger('assigned_to')->nullable();  // الموظف المسؤول بعد التحويل
                $t->string('status', 20)->default('open');
                $t->timestamps();
                $t->index('last_message_at');
                $t->index('needs_attention');
            });
        }

        // ========== الرسائل ==========
        if (!Schema::hasTable('wa_messages')) {
            Schema::create('wa_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->index();
                $t->string('wamid', 128)->nullable()->unique();   // منع التكرار
                $t->string('direction', 4);                        // in | out
                $t->string('source', 16);                         // customer|bot|staff|system|injected
                $t->string('msg_type', 24)->default('text');
                $t->text('body')->nullable();
                $t->json('payload')->nullable();
                $t->string('status', 16)->nullable();              // sent|delivered|read|failed
                $t->text('error_message')->nullable();
                $t->unsignedBigInteger('staff_id')->nullable();
                $t->timestamps();
                $t->index(['conversation_id', 'id']);
            });
        }

        // ========== إعدادات البوت (صف واحد) ==========
        if (!Schema::hasTable('wa_bot_settings')) {
            Schema::create('wa_bot_settings', function (Blueprint $t) {
                $t->id();
                $t->boolean('enabled')->default(false);           // يُنشر مطفأً
                $t->boolean('wa_suspended')->default(false);      // قفل إرسال طارئ
                // بيانات قناة ميتا
                $t->text('wa_token')->nullable();
                $t->string('wa_phone_number_id', 64)->nullable();
                $t->string('wa_verify_token', 128)->nullable();
                $t->text('wa_app_secret')->nullable();
                // الذكاء
                $t->string('provider', 24)->default('anthropic');
                $t->string('model', 64)->default('claude-sonnet-5');
                $t->text('api_key')->nullable();
                $t->text('system_prompt')->nullable();
                $t->text('knowledge_base')->nullable();
                $t->unsignedInteger('context_messages')->default(20);
                $t->unsignedInteger('pause_minutes')->default(30);
                $t->unsignedInteger('max_tool_loops')->default(4);
                $t->unsignedInteger('rate_limit_per_hour')->default(20);
                $t->text('fail_message')->nullable();
                $t->boolean('fail_handoff')->default(true);
                $t->json('tools_config')->nullable();
                $t->timestamps();
            });
        }

        // ========== استهلاك التوكنز ==========
        if (!Schema::hasTable('wa_bot_usage')) {
            Schema::create('wa_bot_usage', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->nullable()->index();
                $t->string('source', 12)->default('live');   // live | playground
                $t->string('model', 64);
                $t->unsignedInteger('calls')->default(1);
                $t->unsignedInteger('prompt_tokens')->default(0);
                $t->unsignedInteger('output_tokens')->default(0);
                $t->unsignedInteger('cache_read_tokens')->default(0);
                $t->unsignedInteger('cache_write_tokens')->default(0);
                $t->timestamp('created_at')->nullable()->index();
            });
        }

        // ========== ملاحظات العميل (ذاكرة طويلة المدى) ==========
        if (!Schema::hasTable('wa_customer_notes')) {
            Schema::create('wa_customer_notes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->index();
                $t->unsignedBigInteger('client_id')->nullable();
                $t->text('note');
                $t->string('source', 12)->default('bot');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        // ========== إيقاف التسويق ==========
        if (!Schema::hasTable('wa_optouts')) {
            Schema::create('wa_optouts', function (Blueprint $t) {
                $t->id();
                $t->string('phone', 32)->unique();
                $t->string('reason', 120)->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        // ========== العروض (يُدخلها المدير — يقرأها البوت) ==========
        if (!Schema::hasTable('offers')) {
            Schema::create('offers', function (Blueprint $t) {
                $t->id();
                $t->string('title', 180);
                $t->string('category', 32)->default('package'); // package|umrah|hajj|flight|visa|hotel|transport|tour
                $t->text('description_client')->nullable();     // ما يراه العميل
                $t->decimal('price_jod', 12, 3)->default(0);
                $t->string('price_per', 16)->default('person'); // person | room | group
                $t->json('includes')->nullable();
                $t->json('excludes')->nullable();
                $t->date('departure_date')->nullable();
                $t->date('return_date')->nullable();
                $t->date('valid_from')->nullable();
                $t->date('valid_to')->nullable();
                $t->unsignedInteger('nights')->nullable();
                $t->string('hotel_name', 120)->nullable();
                $t->unsignedTinyInteger('hotel_rating')->nullable();
                $t->string('airline', 80)->nullable();
                $t->unsignedBigInteger('agent_id')->nullable();
                $t->unsignedInteger('available_seats')->nullable();
                $t->boolean('is_active')->default(true);
                $t->boolean('is_bot_visible')->default(false);  // لا يظهر للبوت حتى تُجهّزه
                $t->unsignedInteger('sort_order')->default(0);
                // 🔒 داخلي — لا يُعاد للبوت إطلاقاً
                $t->decimal('cost_jod', 12, 3)->nullable();
                $t->text('notes_internal')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['is_active', 'is_bot_visible']);
            });
        }

        // ========== طلبات التسعير / تأكيد الحجز ==========
        if (!Schema::hasTable('quote_requests')) {
            Schema::create('quote_requests', function (Blueprint $t) {
                $t->id();
                $t->string('request_number', 32)->nullable()->unique();
                $t->unsignedBigInteger('conversation_id')->nullable()->index();
                $t->unsignedBigInteger('client_id')->nullable()->index();
                $t->unsignedBigInteger('offer_id')->nullable();
                $t->string('phone', 32);
                $t->string('customer_name', 120)->nullable();
                $t->string('type', 20)->default('flight');   // flight|package|visa|hotel|transport|other
                $t->string('route_from', 8)->nullable();
                $t->string('route_to', 8)->nullable();
                $t->date('depart_date')->nullable();
                $t->date('return_date')->nullable();
                $t->unsignedSmallInteger('pax_adults')->default(1);
                $t->unsignedSmallInteger('pax_children')->default(0);
                $t->unsignedSmallInteger('pax_infants')->default(0);
                $t->text('details')->nullable();
                // new = وصل | priced = سُعّر | sent = أُرسل للعميل | won/lost/cancelled
                $t->string('status', 12)->default('new');
                $t->decimal('quoted_price_jod', 12, 3)->nullable();
                $t->text('quoted_note')->nullable();
                $t->unsignedBigInteger('quoted_by')->nullable();
                $t->timestamp('quoted_at')->nullable();
                $t->unsignedBigInteger('assigned_to')->nullable();
                $t->timestamps();
                $t->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'quote_requests', 'offers', 'wa_optouts', 'wa_customer_notes',
            'wa_bot_usage', 'wa_bot_settings', 'wa_messages', 'wa_conversations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
