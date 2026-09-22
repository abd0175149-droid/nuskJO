<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توجيه محادثات الواتساب بالموضوع.
 *
 * المحادثة تحمل وسماً أو أكثر (عمرة، تأشيرات…) يضعها البوت فور فهمه للموضوع
 * أو يضعها الموظف يدوياً. ولكل موضوع موظف أو أكثر مختصّ به؛ الموظف يرى كل
 * محادثة تحمل وسماً من اختصاصه — فالمحادثة بوسمين يراها الموظفان معاً —
 * وواحد منهم «الأساسي» وهو من يصله الإشعار عند التحويل.
 */
return new class extends Migration
{
    public function up(): void
    {
        // وسوم المحادثة — متعدّدة
        if (!Schema::hasTable('wa_conversation_topics')) {
            Schema::create('wa_conversation_topics', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->index();
                $t->string('topic', 30);
                $t->string('source', 10)->default('bot');   // bot | manual
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();

                $t->unique(['conversation_id', 'topic']);
            });
        }

        // اختصاصات الموظفين — موضوع ← موظف
        if (!Schema::hasTable('wa_topic_users')) {
            Schema::create('wa_topic_users', function (Blueprint $t) {
                $t->id();
                $t->string('topic', 30)->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->boolean('is_primary')->default(false);  // من يصله إشعار التحويل
                $t->timestamps();

                $t->unique(['topic', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_conversation_topics');
        Schema::dropIfExists('wa_topic_users');
    }
};
