<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رموز QR الرسمية وبطاقة الحالة ومصدر المحادثة (E38). إضافية ومتساوية الأثر:
 * - wa_qr_links: رمز ورابط قصير من message_qrdls لكل عرض (أو عام بلا عرض لملصق المكتب).
 * - wa_conversation_entries: مصدر بدء المحادثة (رمز أو رابط أو إعلان). لا ctwa_clid ولا نص الإعلان ولا روابط صوره.
 * - offers: مسار بطاقة الحالة 9:16 ووقت توليدها، والرمز المطبوع على بطاقة A4 (null = بلا رمز).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_qr_links')) {
            Schema::create('wa_qr_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offer_id')->nullable()->index();
                $t->string('channel', 12);
                $t->string('label', 80)->nullable();
                $t->string('code', 64)->nullable()->unique();
                $t->string('prefilled_message', 160);
                $t->string('deep_link_url', 191)->nullable();
                $t->string('qr_png_path', 191)->nullable();
                $t->string('qr_svg_path', 191)->nullable();
                $t->boolean('token_ok')->default(false);
                $t->string('status', 12)->default('pending')->index();
                $t->string('last_error', 191)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('wa_conversation_entries')) {
            Schema::create('wa_conversation_entries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->index();
                $t->unsignedBigInteger('message_id')->nullable();
                $t->unsignedBigInteger('offer_id')->nullable()->index();
                $t->unsignedBigInteger('qr_link_id')->nullable();
                $t->string('channel', 12);
                $t->string('referral_source_type', 16)->nullable();
                $t->string('referral_source_id', 64)->nullable();
                $t->string('referral_headline', 120)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['offer_id', 'created_at'], 'wa_conversation_entries_offer_created_index');
                $t->index(['channel', 'created_at'], 'wa_conversation_entries_channel_created_index');
            });
        }

        Schema::table('offers', function (Blueprint $t) {
            if (!Schema::hasColumn('offers', 'story_card_path')) {
                $t->string('story_card_path', 180)->nullable();
            }
            if (!Schema::hasColumn('offers', 'story_generated_at')) {
                $t->timestamp('story_generated_at')->nullable();
            }
            if (!Schema::hasColumn('offers', 'card_qr_link_id')) {
                $t->unsignedBigInteger('card_qr_link_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['card_qr_link_id', 'story_generated_at', 'story_card_path'] as $column) {
            if (Schema::hasColumn('offers', $column)) {
                Schema::table('offers', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        Schema::dropIfExists('wa_conversation_entries');
        Schema::dropIfExists('wa_qr_links');
    }
};
