<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AttendanceLocation;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaContactCard;
use App\Models\WaPlace;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * المواقع وبطاقات الاتصال المعتمدة (E33): مصدر الدبوس والبطاقة الوحيد، للمدير (whatsapp.settings) وحده.
 * مكتب افتراضي واحد للبوت يُفرض في معاملة الحفظ، والحذف تعطيل (رسائل قديمة تشير إليه)، والبطاقة لا تُحفظ بلا موافقة
 * مكتوبة من صاحب الرقم. كل تغيير في سجل التدقيق بالفرق، والهاتف مقنّعاً (phone_masked).
 * الصفحة تعمل قبل العلم wa_share_cards لتعبئة القوائم، والصندوق لا يعرضها حتى يُفعَّل.
 */
class WhatsAppSharedItemsController extends Controller
{
    private const PLACE_FIELDS = ['name', 'address', 'latitude', 'longitude', 'kind', 'is_default', 'bot_allowed', 'is_active', 'sort_order'];

    private const CARD_FIELDS = ['label', 'formatted_name', 'org', 'job_title', 'phone_e164', 'wa_id', 'email', 'url',
        'consent_user_id', 'consent_note', 'consented_at', 'bot_allowed', 'is_active', 'sort_order'];

    public function index()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $ready = Schema::hasTable('wa_places') && Schema::hasTable('wa_contact_cards');

        return Inertia::render('WhatsApp/SharedItems', [
            'title' => 'المواقع وبطاقات الاتصال المعتمدة',
            'ready' => $ready,
            'enabled' => FeatureFlags::on('wa_share_cards'),
            'kinds' => WaPlace::KINDS,
            'places' => $ready ? WaPlace::orderByDesc('is_active')->orderBy('sort_order')->orderBy('id')->get()->map(fn (WaPlace $p) => self::placeRow($p))->values() : [],
            'cards' => $ready ? WaContactCard::orderByDesc('is_active')->orderBy('sort_order')->orderBy('id')->get()->map(fn (WaContactCard $c) => self::cardRow($c))->values() : [],
            // «انسخ من مواقع البصمة»: للقراءة، تعبّئ النموذج ولا تحفظ شيئاً
            'attendancePlaces' => Schema::hasTable('attendance_locations')
                ? AttendanceLocation::active()->geoType()->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('name')->limit(50)
                    ->get(['id', 'name', 'latitude', 'longitude'])->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'latitude' => (string) $l->latitude, 'longitude' => (string) $l->longitude])->values()
                : [],
            'staff' => User::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    /** لمنتقيي المنشئ في الصندوق: الأسماء والأنواع وحدها، والبطاقة بلا رقم */
    public static function pickerItems(): array
    {
        if (!Schema::hasTable('wa_places') || !Schema::hasTable('wa_contact_cards')) {
            return ['places' => [], 'cards' => []];
        }

        return [
            'places' => WaPlace::active()->orderBy('sort_order')->orderBy('id')->limit(50)->get()
                ->map(fn (WaPlace $p) => ['id' => $p->id, 'name' => $p->name, 'address' => $p->address, 'kind' => $p->kind])->values()->all(),
            'cards' => WaContactCard::active()->orderBy('sort_order')->orderBy('id')->limit(50)->get()
                ->map(fn (WaContactCard $c) => ['id' => $c->id, 'label' => $c->label, 'name' => $c->formatted_name, 'org' => $c->org])->values()->all(),
        ];
    }

    // ==================== المواقع ====================

    public function storePlace(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $data = $this->placeData($request);

        $place = DB::transaction(function () use ($data) {
            $place = WaPlace::create($data + ['created_by' => auth()->id(), 'updated_by' => auth()->id()]);
            self::singleDefault($place);

            return $place;
        });
        AuditLog::safe(AuditAction::WaPlaceChange, 'wa_place', $place->id, $place->name, ['action' => 'create', 'after' => self::placeAudit($place)]);

        return back()->with('success', "أُضيف الموقع «{$place->name}»");
    }

    public function updatePlace(Request $request, WaPlace $place)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $data = $this->placeData($request);
        $before = self::placeAudit($place);

        DB::transaction(function () use ($place, $data) {
            $place->update($data + ['updated_by' => auth()->id()]);
            self::singleDefault($place);
        });
        AuditLog::safe(AuditAction::WaPlaceChange, 'wa_place', $place->id, $place->name, ['action' => 'update'] + self::diff($before, self::placeAudit($place->fresh())));

        return back()->with('success', "حُفظ الموقع «{$place->name}»");
    }

    /** تعطيل لا حذف: رسائل قديمة تشير إليه */
    public function destroyPlace(WaPlace $place)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $place->update(['is_active' => false, 'is_default' => false, 'updated_by' => auth()->id()]);
        AuditLog::safe(AuditAction::WaPlaceChange, 'wa_place', $place->id, $place->name, ['action' => 'deactivate']);

        return back()->with('success', "عُطّل الموقع «{$place->name}»");
    }

    // ==================== البطاقات ====================

    public function storeCard(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $data = $this->cardData($request);

        $card = WaContactCard::create($data + ['created_by' => auth()->id(), 'updated_by' => auth()->id()]);
        AuditLog::safe(AuditAction::WaCardChange, 'wa_contact_card', $card->id, $card->label, ['action' => 'create', 'after' => self::cardAudit($card)]);

        return back()->with('success', "أُضيفت البطاقة «{$card->label}»");
    }

    public function updateCard(Request $request, WaContactCard $card)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $data = $this->cardData($request);
        $before = self::cardAudit($card);

        $card->update($data + ['updated_by' => auth()->id()]);
        AuditLog::safe(AuditAction::WaCardChange, 'wa_contact_card', $card->id, $card->label, ['action' => 'update'] + self::diff($before, self::cardAudit($card->fresh())));

        return back()->with('success', "حُفظت البطاقة «{$card->label}»");
    }

    public function destroyCard(WaContactCard $card)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $card->update(['is_active' => false, 'bot_allowed' => false, 'updated_by' => auth()->id()]);
        AuditLog::safe(AuditAction::WaCardChange, 'wa_contact_card', $card->id, $card->label, ['action' => 'deactivate']);

        return back()->with('success', "عُطّلت البطاقة «{$card->label}»");
    }

    // ==================== التحقق والتدقيق ====================

    private function placeData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'address' => 'nullable|string|max:160',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'kind' => ['required', 'string', Rule::in(array_keys(WaPlace::KINDS))],
            'is_default' => 'sometimes|boolean',
            'bot_allowed' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ], [], ['latitude' => 'خط العرض', 'longitude' => 'خط الطول']);
        $data['latitude'] = round((float) $data['latitude'], 7);
        $data['longitude'] = round((float) $data['longitude'], 7);
        // افتراضي البوت للمكاتب الفعّالة وحدها
        $data['is_default'] = $request->boolean('is_default') && $data['kind'] === 'office' && $request->boolean('is_active', true);
        $data['bot_allowed'] = $request->boolean('bot_allowed');
        $data['is_active'] = $request->boolean('is_active', true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return array_intersect_key($data, array_flip(self::PLACE_FIELDS));
    }

    private function cardData(Request $request): array
    {
        $data = $request->validate([
            'label' => 'required|string|max:60',
            'formatted_name' => 'required|string|max:80',
            'org' => 'nullable|string|max:80',
            'job_title' => 'nullable|string|max:60',
            'phone_e164' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'wa_id' => 'nullable|digits_between:8,15',
            'email' => 'nullable|email|max:120',
            'url' => 'nullable|url:https|max:200',
            'consent_user_id' => 'nullable|integer|exists:users,id',
            'consent_note' => 'required|string|max:200',
            'consented_at' => 'required|date|before_or_equal:now',
            // «وافق صاحب الرقم كتابياً» إلزامية
            'consent_confirmed' => 'accepted',
            'bot_allowed' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ], [
            'phone_e164.regex' => 'الرقم بالصيغة الدولية: + ثم من 8 إلى 15 رقماً، مثل +9627XXXXXXXX',
            'consent_confirmed.accepted' => 'لا تُحفظ البطاقة بلا موافقة مكتوبة من صاحب الرقم.',
            'url.url' => 'الرابط يبدأ بـ https وحده.',
        ], ['consent_note' => 'ملاحظة الموافقة', 'consented_at' => 'تاريخ الموافقة', 'wa_id' => 'رقم واتساب']);
        $data['bot_allowed'] = $request->boolean('bot_allowed');
        $data['is_active'] = $request->boolean('is_active', true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return array_intersect_key($data, array_flip(self::CARD_FIELDS));
    }

    /** مكتب افتراضي واحد: حفظ مكتب افتراضي يصفّر البقية في المعاملة نفسها */
    private static function singleDefault(WaPlace $place): void
    {
        if ($place->is_default) {
            WaPlace::whereKeyNot($place->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }

    private static function placeRow(WaPlace $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'address' => $p->address, 'latitude' => (string) $p->latitude, 'longitude' => (string) $p->longitude,
            'kind' => $p->kind, 'is_default' => (bool) $p->is_default, 'bot_allowed' => (bool) $p->bot_allowed, 'is_active' => (bool) $p->is_active,
            'sort_order' => (int) $p->sort_order, 'maps_url' => $p->mapsUrl()];
    }

    /** الجدول يعرض الرقم مقنّعاً، والنموذج (للمدير وحده) يحتاج الرقم كاملاً للتعديل */
    private static function cardRow(WaContactCard $c): array
    {
        return ['id' => $c->id, 'label' => $c->label, 'formatted_name' => $c->formatted_name, 'org' => $c->org, 'job_title' => $c->job_title,
            'phone_e164' => $c->phone_e164, 'phone_masked' => $c->maskedPhone(), 'wa_id' => $c->wa_id, 'email' => $c->email, 'url' => $c->url,
            'consent_user_id' => $c->consent_user_id, 'consent_note' => $c->consent_note, 'consented_at' => $c->consented_at?->format('Y-m-d'),
            'bot_allowed' => (bool) $c->bot_allowed, 'is_active' => (bool) $c->is_active, 'sort_order' => (int) $c->sort_order];
    }

    private static function placeAudit(WaPlace $p): array
    {
        return ['name' => $p->name, 'address' => $p->address, 'latitude' => (string) $p->latitude, 'longitude' => (string) $p->longitude,
            'kind' => $p->kind, 'is_default' => (bool) $p->is_default, 'bot_allowed' => (bool) $p->bot_allowed, 'is_active' => (bool) $p->is_active];
    }

    /** الهاتف مقنّعاً بمفتاح phone_masked (منقّح E09 يحذف المفتاح phone)، وwa_id كذلك، ولا بريد كامل */
    private static function cardAudit(WaContactCard $c): array
    {
        return ['label' => $c->label, 'formatted_name' => $c->formatted_name, 'org' => $c->org, 'job_title' => $c->job_title,
            'phone_masked' => $c->maskedPhone(), 'wa_id_masked' => $c->wa_id ? WaContactCard::mask($c->wa_id) : null,
            'has_email' => filled($c->email), 'url' => $c->url, 'consent_user_id' => $c->consent_user_id,
            'consented_at' => $c->consented_at?->format('Y-m-d'), 'bot_allowed' => (bool) $c->bot_allowed, 'is_active' => (bool) $c->is_active];
    }

    /** الحقول التي تغيّرت وحدها: {before, after} */
    private static function diff(array $before, array $after): array
    {
        $changed = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));

        return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))];
    }
}
