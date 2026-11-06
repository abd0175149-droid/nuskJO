<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaBotSetting;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Services\WhatsApp\Bot\SenderProfile;
use App\Services\WhatsApp\Bot\ToolPolicy;
use App\Services\WhatsApp\BotTools;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * صفحة صلاحيات البوت (/whatsapp/bot-permissions، whatsapp.settings): مصفوفة الأدوات × ملفات المرسل، ووضع البوت لكل رقم.
 * الحفظ يكتب الفرق عن الافتراضي وحده في tool_profiles، فتبقى ترقيات الكود الافتراضية سارية. التحويل لموظف لا يُطفأ.
 * ونطاق الرد (2026-11-06): كل المحادثات، أو تجربة على هواتف مختارة ومحادثات معلَّمة من اللوحة (bot_pilot).
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
            'pilot' => self::pilotProps(),
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

    /** نطاق الرد وهواتف التجربة. هاتف غير صالح يُرفض بالقائمة كلها، فلا يُحفظ نصف ما كتبه المدير */
    public function scope(Request $request)
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(WaBotSetting::BOT_SCOPES)],
            'phones' => ['nullable', 'string', 'max:5000'],
        ]);
        $lines = array_values(array_filter(array_map('trim', preg_split('/[\r\n,،]+/u', (string) ($data['phones'] ?? '')) ?: [])));
        $phones = [];
        $invalid = [];
        foreach ($lines as $line) {
            $c = PhoneNormalizer::canonical($line);
            if ($c === '' || strlen($c) < 8 || strlen($c) > 15) {
                $invalid[] = $line;
            } else {
                $phones[] = $c;
            }
        }
        if ($invalid !== []) {
            return response()->json(['ok' => false, 'error' => 'أرقام غير صالحة: '.implode('، ', array_slice($invalid, 0, 10)), 'invalid' => $invalid], 422);
        }
        $phones = array_values(array_unique($phones));
        if (count($phones) > 200) {
            return response()->json(['ok' => false, 'error' => 'الحد 200 رقم في التجربة.'], 422);
        }
        $s = WaBotSetting::current();
        $before = ['scope' => $s->botScope(), 'phones' => count($s->pilotPhones())];
        $s->forceFill(['bot_scope' => $data['scope'], 'bot_pilot_phones' => $phones ? json_encode($phones) : null])->save();
        AuditLog::safe(AuditAction::WaBotPilotUpdate, 'wa_bot_settings', $s->id, 'bot_scope', ['before' => $before, 'after' => ['scope' => $data['scope'], 'phones' => count($phones)]]);

        return response()->json(['ok' => true, 'pilot' => self::pilotProps()]);
    }

    /** محادثة ضمن تجربة البوت أو خارجها (من لوحة المحادثة أو هذه الصفحة). الربط محصور بسياج الرؤية */
    public function conversation(Request $request, WaConversation $conversation)
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $before = (bool) $conversation->bot_pilot;
        $conversation->forceFill(['bot_pilot' => $data['on']])->save();
        AuditLog::safe(AuditAction::WaBotPilotUpdate, 'wa_conversation', $conversation->id, 'bot_pilot', ['before' => $before, 'after' => (bool) $data['on']]);

        return response()->json(['ok' => true, 'bot_pilot' => (bool) $conversation->bot_pilot]);
    }

    /** @return array{scope: string, phones: list<string>, conversations: list<array{id: int, label: string, phone: ?string}>} */
    public static function pilotProps(): array
    {
        $s = WaBotSetting::current();

        return [
            'scope' => $s->botScope(),
            'phones' => $s->pilotPhones(),
            'conversations' => WaConversation::query()->where('bot_pilot', true)->orderByDesc('id')->limit(200)
                ->get(['id', 'display_name', 'wa_profile_name', 'phone'])
                ->map(fn ($c) => ['id' => $c->id, 'label' => (string) ($c->display_name ?: $c->wa_profile_name ?: $c->phone ?: ('#'.$c->id)), 'phone' => $c->phone])->values()->all(),
        ];
    }
}
