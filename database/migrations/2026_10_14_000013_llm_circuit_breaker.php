<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قاطع مزوّد الذكاء (E12): أوضاعه وعتبته في wa_bot_settings (الوضع shadow افتراضياً: يحسب ويسجّل ولا يحجب)،
 * والمزوّد الاحتياطي (معطّل حتى يُضبط)، وسجل الحوادث wa_llm_incidents بلا بيانات عملاء. إضافية ومتكررة الأمان.
 * الميزانية الزمنية للرد تبقى ثابت E07 (ReplyRun::BUDGET_SECONDS) بحكم A18، فلا عمود لها.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_bot_settings')) {
            Schema::table('wa_bot_settings', function (Blueprint $t) {
                $add = fn (string $c) => !Schema::hasColumn('wa_bot_settings', $c);
                if ($add('breaker_mode')) {
                    $t->string('breaker_mode', 8)->default('shadow');
                }
                if ($add('breaker_threshold')) {
                    $t->unsignedTinyInteger('breaker_threshold')->default(3);
                }
                if ($add('breaker_window_minutes')) {
                    $t->unsignedSmallInteger('breaker_window_minutes')->default(5);
                }
                if ($add('breaker_open_minutes')) {
                    $t->unsignedSmallInteger('breaker_open_minutes')->default(10);
                }
                if ($add('fallback_provider')) {
                    $t->string('fallback_provider', 16)->nullable();
                }
                if ($add('fallback_model')) {
                    $t->string('fallback_model', 64)->nullable();
                }
                if ($add('fallback_api_key')) {
                    $t->text('fallback_api_key')->nullable();
                }
                if ($add('fallback_api_key_rotated_at')) {
                    $t->timestamp('fallback_api_key_rotated_at')->nullable();
                }
            });
        }

        if (!Schema::hasTable('wa_llm_incidents')) {
            Schema::create('wa_llm_incidents', function (Blueprint $t) {
                $t->id();
                $t->string('provider', 16);
                $t->string('model', 64)->nullable();
                $t->string('kind', 20);
                $t->smallInteger('http_status')->nullable();
                $t->string('reason', 200)->nullable();
                $t->boolean('shadow')->default(false);
                $t->timestamp('opened_at')->index();
                $t->timestamp('open_until')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->string('close_reason', 20)->nullable();   // probe | manual | settings_changed
                $t->unsignedSmallInteger('failed_probes')->default(0);
                $t->unsignedInteger('handoffs')->default(0);
                $t->unsignedBigInteger('opened_by')->nullable();
                $t->unsignedBigInteger('closed_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_llm_incidents');
        foreach (['breaker_mode', 'breaker_threshold', 'breaker_window_minutes', 'breaker_open_minutes',
            'fallback_provider', 'fallback_model', 'fallback_api_key', 'fallback_api_key_rotated_at'] as $column) {
            if (Schema::hasColumn('wa_bot_settings', $column)) {
                Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
