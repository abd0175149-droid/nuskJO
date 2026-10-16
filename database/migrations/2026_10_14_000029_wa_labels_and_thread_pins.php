<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التصنيفات والملاحظة المثبتة والترجمة عند الطلب (E29): ثلاثة جداول داخلية منفصلة عن المواضيع وعن الرسائل،
 * وأعمدة تأكيد الطبقة المدفوعة لمزوّد الذكاء على wa_bot_settings.
 * - wa_labels: قائمة يديرها المدير. wa_conversation_labels: الوسوم على المحادثات، بلا FK كبقية جداول واتساب
 *   (الحذف المتسلسل في الكود). لا تمس wa_conversation_topics، فلا أثر لها في الرؤية ولا في التوكيل.
 * - wa_thread_pins: ملاحظة مثبتة واحدة لكل محادثة (فهرس فريد جزئي) وحتى 3 رسائل مثبتة. لا يقرؤه البوت.
 * إضافية بحتة ومتكررة الأمان: لا تُدرج تصنيفات، ولا تعيد بناء أي جدول قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_labels')) {
            Schema::create('wa_labels', function (Blueprint $t) {
                $t->id();
                $t->string('name', 30)->unique();
                // مفتاح من لوحة ثابتة (WaLabel::COLORS)، لا لون حر
                $t->string('color', 12)->default('gray');
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('wa_conversation_labels')) {
            Schema::create('wa_conversation_labels', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->index();
                $t->unsignedBigInteger('label_id')->index();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->unique(['conversation_id', 'label_id']);
            });
        }

        if (!Schema::hasTable('wa_thread_pins')) {
            Schema::create('wa_thread_pins', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->index();
                // note | message
                $t->string('kind', 8);
                $t->unsignedBigInteger('message_id')->nullable();
                // حتى 500 حرف، يُفرض في الكود
                $t->text('note')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                // الرسالة نفسها لا تُثبَّت مرتين (NULL الملاحظة لا يصطدم)
                $t->unique(['conversation_id', 'message_id']);
            });
        }
        // ملاحظة مثبتة واحدة لكل محادثة: فهرس فريد جزئي (SQLite)
        if (Schema::hasTable('wa_thread_pins') && in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS wa_thread_pins_one_note ON wa_thread_pins(conversation_id) WHERE kind = 'note'");
        }

        // تأكيد المدير أن مفتاح المزوّد على الطبقة المدفوعة (شرط الترجمة). البصمة تربط التأكيد بالمزوّد والمفتاح
        // اللذين أُكّد عليهما، فمفتاح بيئة جديد يُسقطه أيضاً
        if (Schema::hasTable('wa_bot_settings')) {
            if (!Schema::hasColumn('wa_bot_settings', 'llm_terms_confirmed_at')) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->timestamp('llm_terms_confirmed_at')->nullable());
            }
            if (!Schema::hasColumn('wa_bot_settings', 'llm_terms_confirmed_by')) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->unsignedBigInteger('llm_terms_confirmed_by')->nullable());
            }
            if (!Schema::hasColumn('wa_bot_settings', 'llm_terms_fp')) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->string('llm_terms_fp', 16)->nullable());
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_thread_pins');
        Schema::dropIfExists('wa_conversation_labels');
        Schema::dropIfExists('wa_labels');
        foreach (['llm_terms_fp', 'llm_terms_confirmed_by', 'llm_terms_confirmed_at'] as $col) {
            if (Schema::hasColumn('wa_bot_settings', $col)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
