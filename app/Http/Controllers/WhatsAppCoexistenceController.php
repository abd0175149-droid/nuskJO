<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\User;
use App\Models\WaBotSetting;
use App\Services\WhatsApp\CoexistenceSync;
use App\Services\WhatsApp\Settings\SettingsSchema;
use App\Services\WhatsApp\Settings\SettingsWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * بطاقة «📱 التعايش» (E39، أي علم coexistence_*، whatsapp.settings): حامل جهاز التعايش ومدة إيقاف البوت بعد الصدى.
 * الحامل مستخدم نشط يملك whatsapp.view_all (الهاتف يرى كل المحادثات)، وكل تغيير يُدقَّق دائماً.
 */
class WhatsAppCoexistenceController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorizeCoexistence();

        return response()->json(['coexistence' => CoexistenceSync::panel()])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeCoexistence();
        $data = $request->validate([
            'holder_user_id' => 'nullable|integer|min:1',
            'echo_pause_minutes' => ['nullable', ...SettingsSchema::fieldRules('echo_pause_minutes')],
        ]);

        $holderId = $data['holder_user_id'] ?? null;
        $holder = $holderId !== null ? User::where('is_active', true)->find($holderId) : null;
        if ($holderId !== null && ($holder === null || !$holder->can('whatsapp.view_all'))) {
            throw ValidationException::withMessages(['holder_user_id' => 'حامل الجهاز يجب أن يكون مستخدماً نشطاً يرى كل المحادثات (whatsapp.view_all)، لأن الهاتف يرى كل شيء.']);
        }

        $before = (int) WaBotSetting::current()->coexistence_holder_user_id ?: null;
        SettingsWriter::apply([
            'coexistence_holder_user_id' => $holderId,
            'echo_pause_minutes' => $data['echo_pause_minutes'] ?? null,
        ], null, AuditAction::WaCoexistenceHolderSet, [
            'holder_from' => $before,
            'holder_to' => $holderId,
            'holder_name' => $holder?->name,
        ]);

        return response()->json(['coexistence' => CoexistenceSync::panel()]);
    }

    private function authorizeCoexistence(): void
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        abort_unless(CoexistenceSync::active(), 404);
    }
}
