<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\WaBotSetting;
use App\Models\WaChannel;
use App\Models\WaMessageType;
use App\Models\WaOutbox;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\System\Catalog;
use App\Services\WhatsApp\System\Renderer;
use App\Services\WhatsApp\System\Scheduler;
use App\Services\WhatsApp\System\TemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * صفحة رسائل النظام (/whatsapp/system-messages، whatsapp.settings): الأنواع الاثنا عشر بقوالبها وتوقيتها ووضعها
 * ومرحلتها، والمعاينة على مصدر حقيقي، والإرسال التجريبي لأرقام الاختبار، و«إطلاق» النوع للعملاء، ووضع الاختبار العام.
 * قرار المالك: كل الأنواع تلقائية، بعد اختبار بسيط لكل نوع (الإطلاق يشترط إرسالاً تجريبياً واحداً على الأقل).
 */
class WhatsAppSystemMessageController extends Controller
{
    public function index()
    {
        $s = WaBotSetting::current();
        $since = now()->subDays(30);

        return Inertia::render('WhatsApp/SystemMessages', [
            'types' => collect(Catalog::TYPES)->keys()->map(fn ($k) => $this->present($k, $since))->values(),
            'groups' => Catalog::GROUPS,
            'channels' => WaChannel::query()->active()->orderBy('sort')->get(['id', 'name', 'color']),
            'testMode' => (bool) $s->system_test_mode,
            'testRecipients' => Scheduler::testRecipients(),
            'samples' => [
                'invoice' => Invoice::where('status', 'approved')->orderByDesc('id')->limit(15)->get(['id', 'invoice_number'])->map(fn ($i) => ['id' => $i->id, 'label' => $i->invoice_number])->values(),
                'receipt' => Receipt::where('status', 'approved')->orderByDesc('id')->limit(15)->get(['id', 'receipt_number'])->map(fn ($r) => ['id' => $r->id, 'label' => $r->receipt_number])->values(),
            ],
        ]);
    }

    public function update(Request $request, string $key)
    {
        abort_unless(Catalog::exists($key), 404);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(WaMessageType::MODES)],
            'template' => ['sometimes', 'nullable', 'string', 'max:3500'],
            'channel_id' => ['sometimes', 'nullable', 'integer', 'exists:wa_channels,id'],
            'timing' => ['sometimes', 'array'],
            'timing.at' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timing.offsets' => ['sometimes', 'array', 'max:4'], 'timing.offsets.*' => ['integer', 'between:-30,30'],
            'timing.delay_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'expires_after_minutes' => ['sometimes', 'nullable', 'integer', 'between:30,10080'],
            'priority' => ['sometimes', 'nullable', 'integer', 'between:1,9'],
        ]);
        if (array_key_exists('template', $data) && filled($data['template'])) {
            $unknown = array_diff(TemplateRenderer::variables($data['template']), Catalog::TYPES[$key]['vars'], $key === 'lead.followup' ? ['text'] : []);
            if ($unknown) {
                return response()->json(['ok' => false, 'error' => 'متغيرات غير معروفة لهذا النوع: '.implode('، ', $unknown)], 422);
            }
        }
        $row = WaMessageType::firstOrNew(['key' => $key]);
        $before = $row->only(['enabled', 'mode', 'template', 'channel_id', 'timing']);
        if (isset($data['timing'])) {
            $data['timing'] = array_intersect_key($data['timing'], array_flip(['at', 'offsets', 'delay_minutes']));
        }
        $row->fill($data + ['updated_by' => $request->user()->id])->save();
        // المفتوح من نوع أُطفئ يُلغى، والمواعيد بعد تغيير التوقيت تُعاد بالمسح التالي
        if (($data['enabled'] ?? true) === false || ($data['mode'] ?? null) === 'off') {
            WaOutbox::where('type_key', $key)->whereIn('status', WaOutbox::OPEN)->update(['status' => WaOutbox::CANCELLED, 'defer_reason' => 'أُطفئ النوع', 'updated_at' => now()]);
        }
        AuditLog::safe(AuditAction::WaMessageTypeUpdate, 'wa_message_type', $row->id, $key, ['before' => $before, 'after' => $row->only(array_keys($before))]);

        return response()->json(['ok' => true, 'type' => $this->present($key, now()->subDays(30))]);
    }

    /** المعاينة على مصدر حقيقي (فاتورة أو سند) بالقالب المكتوب الآن، بلا حفظ ولا إرسال */
    public function preview(Request $request, string $key)
    {
        abort_unless(Catalog::exists($key), 404);
        $data = $request->validate(['template' => ['required', 'string', 'max:3500'], 'source_id' => ['nullable', 'integer']]);
        $source = Catalog::TYPES[$key]['source'];
        if (in_array($source, ['invoice', 'receipt'], true) && empty($data['source_id'])) {
            return response()->json(['ok' => false, 'error' => 'اختر فاتورة أو سنداً للمعاينة.'], 422);
        }
        if (!in_array($source, ['invoice', 'receipt'], true)) {
            $vars = array_fill_keys(Catalog::TYPES[$key]['vars'], '…') + ['client_name' => 'أحمد', 'company' => (string) (\App\Services\CompanyInfo::all()['name'] ?? ''), 'text' => 'نص الموظف'];

            return response()->json(TemplateRenderer::render($data['template'], $vars));
        }

        return response()->json(Renderer::preview($key, $data['template'], $source, (int) $data['source_id']));
    }

    /** إرسال تجريبي الآن لأرقام الاختبار على مصدر حقيقي (أو بلا مصدر لما لا يحتاجه) */
    public function test(Request $request, string $key)
    {
        abort_unless(Catalog::exists($key), 404);
        $data = $request->validate(['source_id' => ['nullable', 'integer']]);
        $recipients = Scheduler::testRecipients();
        if ($recipients === []) {
            return response()->json(['ok' => false, 'error' => 'أضف رقم اختبار واحداً على الأقل أولاً.'], 422);
        }
        $def = Catalog::TYPES[$key];
        $source = $def['source'];
        $sourceId = (int) ($data['source_id'] ?? 0);
        if (in_array($source, ['invoice', 'receipt'], true) && $sourceId <= 0) {
            return response()->json(['ok' => false, 'error' => 'اختر فاتورة أو سنداً للتجربة.'], 422);
        }
        if ($source === 'admin') {
            $sourceId = (int) (\App\Models\WaAdminNumber::query()->where('is_active', true)->value('id') ?? 0);
        }
        $n = 0;
        foreach ($recipients as $i => $phone) {
            $item = \App\Services\WhatsApp\Sending\Outbox::put("test:{$key}:{$sourceId}:".now()->format('YmdHis').":{$i}", [
                'type_key' => $key, 'phone' => $phone, 'is_test' => true, 'priority' => 9, 'due_at' => now(), 'expires_at' => now()->addHour(),
                'source_type' => $source, 'source_id' => $sourceId, 'channel_id' => Catalog::effective($key)['channel_id'],
                'payload' => ['kind' => 'system', 'anchor' => [], 'test_of' => null] + ($key === 'lead.followup' ? ['text' => 'نص متابعة تجريبي'] : []),
                'created_by' => $request->user()->id,
            ]);
            $n += $item->wasRecentlyCreated ? 1 : 0;
        }
        $row = WaMessageType::firstOrNew(['key' => $key]);
        $row->forceFill(['tests_count' => (int) $row->tests_count + 1, 'last_test_at' => now()])->save();
        AuditLog::safe(AuditAction::WaMessageTypeUpdate, 'wa_message_type', $row->id, $key, ['action' => 'test', 'recipients' => count($recipients)]);

        return response()->json(['ok' => true, 'queued' => $n, 'type' => $this->present($key, now()->subDays(30))]);
    }

    /** إطلاق النوع للعملاء (stage=live)، أو إعادته للاختبار */
    public function stage(Request $request, string $key)
    {
        abort_unless(Catalog::exists($key), 404);
        $data = $request->validate(['stage' => ['required', Rule::in(WaMessageType::STAGES)]]);
        $row = WaMessageType::firstOrNew(['key' => $key]);
        if ($data['stage'] === 'live' && (int) $row->tests_count < 1) {
            return response()->json(['ok' => false, 'error' => 'جرّب النوع مرة واحدة على أرقام الاختبار قبل إطلاقه.'], 422);
        }
        $row->fill(['stage' => $data['stage'], 'updated_by' => $request->user()->id])->save();
        AuditLog::safe(AuditAction::WaMessageTypeUpdate, 'wa_message_type', $row->id, $key, ['action' => 'stage', 'stage' => $data['stage']]);

        return response()->json(['ok' => true, 'type' => $this->present($key, now()->subDays(30))]);
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['test_mode' => ['sometimes', 'boolean'], 'test_recipients' => ['sometimes', 'array', 'max:10'], 'test_recipients.*' => ['string', 'max:20']]);
        $s = WaBotSetting::current();
        $fill = [];
        if (array_key_exists('test_mode', $data)) {
            $fill['system_test_mode'] = (bool) $data['test_mode'];
        }
        if (array_key_exists('test_recipients', $data)) {
            $phones = array_values(array_unique(array_filter(array_map(fn ($p) => PhoneNormalizer::canonical((string) $p), $data['test_recipients']), fn ($p) => strlen($p) >= 8)));
            $fill['test_recipients'] = json_encode($phones);
        }
        $s->forceFill($fill)->save();
        AuditLog::safe(AuditAction::WaMessageTypeUpdate, 'wa_bot_settings', $s->id, 'test_settings', ['test_mode' => $fill['system_test_mode'] ?? null, 'recipients' => isset($phones) ? count($phones) : null]);

        return response()->json(['ok' => true, 'testMode' => (bool) $s->system_test_mode, 'testRecipients' => Scheduler::testRecipients()]);
    }

    private function present(string $key, $since): array
    {
        $t = Catalog::effective($key);
        $counts = WaOutbox::query()->where('type_key', $key)->where('updated_at', '>=', $since)
            ->selectRaw('status, is_test, count(*) as n')->groupBy('status', 'is_test')->get();
        $sum = fn (array $statuses, bool $test = false) => (int) $counts->whereIn('status', $statuses)->where('is_test', $test)->sum('n');

        return [
            'key' => $key, 'label' => $t['label'], 'group' => $t['group'], 'description' => $t['description'], 'source' => $t['source'],
            'enabled' => (bool) $t['enabled'], 'mode' => $t['mode'], 'stage' => $t['stage'], 'channel_id' => $t['channel_id'],
            'template' => $t['template'], 'default_template' => Catalog::TYPES[$key]['template'], 'vars' => $t['vars'], 'timing' => $t['timing'],
            'attachment' => $t['attachment'] ?? null, 'tests_count' => $t['tests_count'], 'last_test_at' => optional($t['last_test_at'])->toIso8601String(),
            'stats' => ['sent' => $sum([WaOutbox::SENT]), 'open' => $sum(WaOutbox::OPEN), 'failed' => $sum([WaOutbox::FAILED, WaOutbox::EXPIRED, WaOutbox::DENIED]),
                'cancelled' => $sum([WaOutbox::CANCELLED]), 'tests' => $sum([WaOutbox::SENT], true)],
        ];
    }
}
