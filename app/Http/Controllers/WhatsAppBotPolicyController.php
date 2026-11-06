<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Models\WaChannel;
use App\Services\WhatsApp\Bot\SenderProfile;
use App\Services\WhatsApp\Bot\ToolPolicy;
use App\Services\WhatsApp\BotTools;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * صفحة صلاحيات البوت (/whatsapp/bot-permissions، whatsapp.settings): مصفوفة الأدوات × ملفات المرسل، ووضع البوت لكل رقم.
 * الحفظ يكتب الفرق عن الافتراضي وحده في tool_profiles، فتبقى ترقيات الكود الافتراضية سارية. التحويل لموظف لا يُطفأ.
 */
class WhatsAppBotPolicyController extends Controller
{
    public function index()
    {
        return Inertia::render('WhatsApp/BotPermissions', [
            'tools' => collect(BotTools::TOOLS)->only(array_keys(ToolPolicy::DEFAULTS))->map(fn ($t, $k) => [
                'key' => $k, 'label' => $t['label'], 'tier' => $t['tier'] ?? 'read', 'locked' => in_array($k, ToolPolicy::ALWAYS, true),
                'limited' => in_array($k, ToolPolicy::LIMITED, true),
            ])->values(),
            'profiles' => collect(['guest', 'lead', 'client', 'traveler'])->map(fn ($p) => ['key' => $p, 'label' => SenderProfile::LABELS[$p]])->values(),
            'matrix' => ToolPolicy::matrix(),
            'defaults' => collect(ToolPolicy::DEFAULTS)->map(fn ($ps) => array_fill_keys($ps, true)),
            'channels' => WaChannel::query()->orderBy('sort')->get(['id', 'name', 'color', 'bot_mode', 'roles']),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['matrix' => ['required', 'array']]);
        $overrides = [];
        foreach (ToolPolicy::DEFAULTS as $tool => $profiles) {
            if (in_array($tool, ToolPolicy::ALWAYS, true)) {
                continue;
            }
            foreach (['guest', 'lead', 'client', 'traveler'] as $p) {
                $want = (bool) data_get($data['matrix'], "{$tool}.{$p}", in_array($p, $profiles, true));
                if ($want !== in_array($p, $profiles, true)) {
                    $overrides[$tool][$p] = $want;
                }
            }
        }
        $s = WaBotSetting::current();
        $before = ToolPolicy::overrides();
        $s->forceFill(['tool_profiles' => $overrides ? json_encode($overrides) : null])->save();
        AuditLog::safe(AuditAction::WaBotPolicyUpdate, 'wa_bot_settings', $s->id, 'tool_profiles', ['before' => $before, 'after' => $overrides]);

        return response()->json(['ok' => true, 'matrix' => ToolPolicy::matrix()]);
    }

    public function channel(Request $request, WaChannel $channel)
    {
        $data = $request->validate(['bot_mode' => ['required', Rule::in(WaChannel::BOT_MODES)]]);
        $before = $channel->bot_mode;
        $channel->forceFill(['bot_mode' => $data['bot_mode']])->save();
        AuditLog::safe(AuditAction::WaBotPolicyUpdate, 'wa_channel', $channel->id, 'bot_mode', ['before' => $before, 'after' => $data['bot_mode']]);

        return response()->json(['ok' => true]);
    }
}
