<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaLabel;
use App\Services\WhatsApp\LabelService;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * إدارة قائمة تصنيفات المحادثات (E29): للمدير (whatsapp.settings). الصفحة تعمل قبل العلم wa_labels لإعداد القائمة
 * الأولى، والصندوق لا يعرضها حتى يُفعَّل. الأسماء بلا أرقام طويلة ولا بريد ولا روابط، والألوان من لوحة ثابتة.
 */
class WhatsAppLabelController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $usage = LabelService::available()
            ? DB::table('wa_conversation_labels')->selectRaw('label_id, count(*) as n')->groupBy('label_id')->pluck('n', 'label_id')
            : collect();

        return Inertia::render('WhatsApp/Labels', [
            'title' => 'تصنيفات المحادثات',
            'labels' => LabelService::available() ? WaLabel::orderBy('sort_order')->orderBy('id')->get()->map(fn (WaLabel $l) => $l->chip() + [
                'usage' => (int) ($usage[$l->id] ?? 0),
                'updated_at' => $l->updated_at?->format('Y-m-d H:i'),
            ])->values() : [],
            'colors' => WaLabel::COLORS,
            'max' => WaLabel::MAX_PER_CONVERSATION,
            'enabled' => FeatureFlags::on('wa_labels'),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $data = $this->validated($request);

        $label = WaLabel::create($data + [
            'sort_order' => (int) WaLabel::max('sort_order') + 1,
            'created_by' => auth()->id(),
        ]);
        AuditLog::safe(AuditAction::WaLabelCreate, 'wa_label', $label->id, $label->name, ['color' => $label->color, 'active' => $label->is_active]);

        return back()->with('success', "أُضيف التصنيف «{$label->name}»");
    }

    public function update(Request $request, WaLabel $label)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $data = $this->validated($request, $label);

        $before = $label->only(['name', 'color', 'is_active']);
        $label->update($data);
        AuditLog::safe(AuditAction::WaLabelUpdate, 'wa_label', $label->id, $label->name, ['before' => $before, 'after' => $label->only(['name', 'color', 'is_active'])]);

        return back()->with('success', $label->is_active ? "حُفظ التصنيف «{$label->name}»" : "عُطّل التصنيف «{$label->name}»: يبقى على المحادثات بلون باهت ولا يُختار من جديد");
    }

    /** الحذف لغير المستعمل، أو مع detach_all يُزال عن كل المحادثات أولاً */
    public function destroy(Request $request, WaLabel $label)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $detachAll = $request->validate(['detach_all' => 'sometimes|boolean'])['detach_all'] ?? false;

        $name = $label->name;
        $detached = LabelService::destroy($label, (bool) $detachAll);
        AuditLog::safe(AuditAction::WaLabelDelete, 'wa_label', $label->id, $name, ['detached' => $detached]);

        return back()->with('success', $detached ? "حُذف «{$name}» وأُزيل عن {$detached} محادثة" : "حُذف التصنيف «{$name}»");
    }

    public function reorder(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) {
                WaLabel::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return back()->with('success', 'حُفظ الترتيب');
    }

    /** @return array{name: string, color: string, is_active: bool} */
    private function validated(Request $request, ?WaLabel $label = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'color' => ['required', 'string', Rule::in(WaLabel::COLORS)],
            'is_active' => 'sometimes|boolean',
        ]);
        $name = LabelService::validateName($data['name']);
        $request->merge(['name' => $name])->validate([
            'name' => [Rule::unique('wa_labels', 'name')->ignore($label?->id)],
        ], ['name.unique' => 'يوجد تصنيف بهذا الاسم']);

        return ['name' => $name, 'color' => $data['color'], 'is_active' => $request->boolean('is_active', $label?->is_active ?? true)];
    }
}
