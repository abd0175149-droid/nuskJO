<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صلاحيات أدوات البوت حسب ملف المرسل (2026-11-06، docs/whatsapp/bot-profiles.md): wa_bot_settings.tool_profiles
 * تعديلات المدير على المصفوفة الافتراضية في Bot\ToolPolicy ({أداة: {ملف: y|a|-}}). وضع البوت لكل رقم في wa_channels.bot_mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('wa_bot_settings', 'tool_profiles')) {
            Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->json('tool_profiles')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wa_bot_settings', 'tool_profiles')) {
            Schema::table('wa_bot_settings', fn (Blueprint $t) => $t->dropColumn('tool_profiles'));
        }
    }
};
