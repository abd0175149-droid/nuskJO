<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أرقام الإدارة وقاعدة المعرفة كمدخلات.
 *
 *  wa_admin_numbers     رقم واتساب لموظف إدارة + الأدوات المسموحة له
 *  wa_admin_actions     سجلّ كل أداة إدارية نُفّذت أو رُفضت أو عُرضت معاينتها
 *  wa_knowledge_entries قاعدة المعرفة مقسّمة لمعلومات مستقلة تُضاف وتُعدّل وتُحذف
 *
 * قاعدة المعرفة كانت نصّاً واحداً في wa_bot_settings.knowledge_base بعناوين «=== … ===».
 * تُقسَّم هنا على العناوين، ويبقى العمود القديم كما هو نسخةً احتياطية لا يقرؤها البوت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wa_knowledge_entries')) {
            Schema::create('wa_knowledge_entries', function (Blueprint $t) {
                $t->id();
                $t->string('title', 150);
                $t->text('content');
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort_order')->default(0);
                $t->string('source', 12)->default('dashboard');          // dashboard | whatsapp | import
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_admin_id')->nullable();
                $t->timestamps();
                $t->index(['is_active', 'sort_order']);
            });
        }

        if (!Schema::hasTable('wa_admin_numbers')) {
            Schema::create('wa_admin_numbers', function (Blueprint $t) {
                $t->id();
                $t->string('phone', 32)->unique();                       // بصيغة PhoneNormalizer::canonical
                $t->string('name', 100);
                $t->unsignedBigInteger('user_id')->nullable();           // حساب الموظف في النظام إن وُجد
                $t->json('permissions')->nullable();
                $t->boolean('is_active')->default(true);
                $t->text('notes')->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('wa_admin_actions')) {
            Schema::create('wa_admin_actions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('admin_number_id')->index();
                $t->unsignedBigInteger('conversation_id')->nullable();
                $t->string('tool', 40);
                $t->string('action', 40)->nullable();
                $t->string('status', 16);                                // done | preview | denied | error
                $t->text('summary')->nullable();
                $t->json('input')->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }

        // ── ترحيل قاعدة المعرفة النصّية إلى مدخلات ──
        if (DB::table('wa_knowledge_entries')->count() === 0 && Schema::hasTable('wa_bot_settings')) {
            $kb = (string) DB::table('wa_bot_settings')->value('knowledge_base');
            $now = now();
            foreach (self::split($kb) as $i => [$title, $content]) {
                DB::table('wa_knowledge_entries')->insert([
                    'title' => mb_substr($title, 0, 150),
                    'content' => $content,
                    'is_active' => true,
                    'sort_order' => $i + 1,
                    'source' => 'import',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** يقسّم النصّ على العناوين «=== عنوان ===»، وما قبل أوّل عنوان يصير «عام» */
    private static function split(string $kb): array
    {
        $out = [];
        $title = 'عام';
        $buf = [];

        $flush = function () use (&$out, &$title, &$buf) {
            $content = trim(implode("\n", $buf));
            if ($content !== '') {
                $out[] = [$title, $content];
            }
            $buf = [];
        };

        foreach (preg_split('/\R/u', $kb) as $line) {
            if (preg_match('/^\s*===\s*(.+?)\s*===\s*$/u', $line, $m)) {
                $flush();
                $title = $m[1];
                continue;
            }
            $buf[] = $line;
        }
        $flush();

        return $out;
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_admin_actions');
        Schema::dropIfExists('wa_admin_numbers');
        Schema::dropIfExists('wa_knowledge_entries');
    }
};
