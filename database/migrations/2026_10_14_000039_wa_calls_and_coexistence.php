<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المكالمات والتعايش (E39، جاهزية مشروطة بقرار المالك وكل أعلامها مطفأة):
 * - wa_calls: سجل المكالمة وحده. لا عمود لـ SDP ولا للصوت ولا لعنوان IP.
 * - wa_history_chunks: سجل التعايش السابق (history) محفوظاً مشفّراً بانتظار قرار الاستيراد، بمدة قصوى ثم يُمحى (C012).
 * - إعدادات في wa_bot_settings: آخر قراءة لإعدادات الاتصال من ميتا، وطريقة الرد، والرفض الآلي، ونص المتابعة،
 *   ومدة إيقاف البوت بعد الصدى، وحامل جهاز التعايش.
 * إضافة فقط بلا إعادة بناء (wa_messages وwa_conversations لا تُمسّان)، ومتكررة الأمان.
 */
return new class extends Migration
{
    private const SETTINGS = [
        'calling_snapshot' => 'json',
        'calls_answer_mode' => 'mode',
        'calls_auto_reject' => 'bool',
        'calls_followup_text' => 'text',
        'calls_followup_text_en' => 'text',
        'echo_pause_minutes' => 'int',
        'coexistence_holder_user_id' => 'id',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('wa_calls')) {
            Schema::create('wa_calls', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->index();
                $t->string('wa_call_id', 128)->unique();
                // USER_INITIATED | BUSINESS_INITIATED
                $t->string('direction', 20);
                // ringing | completed | missed | rejected | accepted
                $t->string('status', 16);
                $t->timestamp('connect_at')->nullable();
                $t->timestamp('start_time')->nullable();
                $t->timestamp('end_time')->nullable();
                $t->unsignedInteger('duration_seconds')->nullable();
                $t->boolean('missed')->default(false);
                // رفضناها آلياً (C045) لا العميل
                $t->boolean('rejected_by_us')->default(false);
                $t->unsignedBigInteger('followup_message_id')->nullable();
                // رسالة النظام في الخيط: ملؤه يعني أن الانتهاء عولج (إعادة ميتا لا تكرره)
                $t->unsignedBigInteger('thread_message_id')->nullable();
                $t->timestamps();
                $t->index(['missed', 'created_at']);
            });
        }

        if (!Schema::hasTable('wa_history_chunks')) {
            Schema::create('wa_history_chunks', function (Blueprint $t) {
                $t->id();
                // بصمة القيمة: إعادة ميتا للدفعة نفسها لا تُحفظ مرتين
                $t->string('sha256', 64)->unique();
                $t->unsignedTinyInteger('phase')->nullable();
                $t->unsignedInteger('chunk_order')->nullable();
                $t->unsignedTinyInteger('progress')->nullable();
                $t->unsignedInteger('threads')->default(0);
                $t->unsignedInteger('messages')->default(0);
                // held | imported | discarded | expired
                $t->string('status', 12)->default('held');
                // قيمة الحقل history وحدها، مشفّرة بـ APP_KEY، وتُمحى عند الاستيراد أو الإهمال أو انتهاء المدة
                $t->longText('payload')->nullable();
                $t->unsignedInteger('imported_messages')->default(0);
                $t->timestamp('received_at')->nullable();
                $t->timestamp('purge_after')->nullable()->index();
                $t->timestamp('settled_at')->nullable();
                $t->timestamps();
            });
        }

        if (Schema::hasTable('wa_bot_settings')) {
            foreach (self::SETTINGS as $column => $kind) {
                if (Schema::hasColumn('wa_bot_settings', $column)) {
                    continue;
                }
                Schema::table('wa_bot_settings', function (Blueprint $t) use ($column, $kind) {
                    match ($kind) {
                        'json' => $t->json($column)->nullable(),
                        'mode' => $t->string($column, 8)->default('none'),
                        'bool' => $t->boolean($column)->default(false),
                        'text' => $t->text($column)->nullable(),
                        'int' => $t->unsignedInteger($column)->nullable(),
                        'id' => $t->unsignedBigInteger($column)->nullable(),
                    };
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::SETTINGS)) as $column) {
            if (Schema::hasColumn('wa_bot_settings', $column)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        Schema::dropIfExists('wa_history_chunks');
        Schema::dropIfExists('wa_calls');
    }
};
