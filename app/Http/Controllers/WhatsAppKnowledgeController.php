<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaAdminNumber;
use App\Models\WaKnowledgeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * قاعدة معرفة بوت العملاء — معلومات مستقلة تُضاف وتُعدّل وتُحذف وتُرتَّب.
 * المعلومات الفعّالة كلها تُرسل للبوت مع كل رسالة، فطولها الكلّي محدود.
 */
class WhatsAppKnowledgeController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $entries = WaKnowledgeEntry::orderBy('sort_order')->orderBy('id')->get();
        $users = User::whereIn('id', $entries->pluck('updated_by_user_id')->filter()->unique())->pluck('name', 'id');
        $admins = WaAdminNumber::whereIn('id', $entries->pluck('updated_by_admin_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('WhatsApp/Knowledge', [
            'title' => 'قاعدة معرفة البوت',
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'content' => $e->content,
                'is_active' => (bool) $e->is_active,
                'source' => $e->source,
                'updated_by' => $e->source === 'whatsapp'
                    ? ($admins[$e->updated_by_admin_id] ?? 'رقم إدارة') . ' (واتساب)'
                    : ($users[$e->updated_by_user_id] ?? null),
                'updated_at' => $e->updated_at?->format('Y-m-d H:i'),
            ])->values(),
            'limits' => [
                'title' => WaKnowledgeEntry::MAX_TITLE,
                'content' => WaKnowledgeEntry::MAX_CONTENT,
                'total' => WaKnowledgeEntry::MAX_TOTAL,
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $data = $this->validated($request, null);
        $e = WaKnowledgeEntry::create($data + [
            'sort_order' => (int) WaKnowledgeEntry::max('sort_order') + 1,
            'source' => 'dashboard',
            'updated_by_user_id' => auth()->id(),
        ]);

        AuditLog::log('create', 'wa_knowledge_entry', $e->id, $e->title);

        return back()->with('success', "أُضيفت المعلومة «{$e->title}»");
    }

    public function update(Request $request, WaKnowledgeEntry $entry)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $before = $entry->only(['title', 'content', 'is_active']);
        $entry->update($this->validated($request, $entry->id) + [
            'source' => 'dashboard',
            'updated_by_user_id' => auth()->id(),
            'updated_by_admin_id' => null,
        ]);

        AuditLog::log('update', 'wa_knowledge_entry', $entry->id, $entry->title, ['before' => $before]);

        return back()->with('success', "حُفظت المعلومة «{$entry->title}»");
    }

    public function destroy(WaKnowledgeEntry $entry)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        // النسخة الكاملة في سجلّ التدقيق — تُستعاد منه عند الحاجة
        AuditLog::log('delete', 'wa_knowledge_entry', $entry->id, $entry->title, $entry->only(['title', 'content', 'is_active', 'sort_order']));
        $entry->delete();

        return back()->with('success', 'حُذفت المعلومة');
    }

    public function reorder(Request $request)
    {
        abort_unless(auth()->user()->can('whatsapp.settings'), 403);

        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) {
                WaKnowledgeEntry::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return back()->with('success', 'حُفظ الترتيب');
    }

    private function validated(Request $request, ?int $exceptId): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:' . WaKnowledgeEntry::MAX_TITLE,
            'content' => 'required|string|max:' . WaKnowledgeEntry::MAX_CONTENT,
            'is_active' => 'boolean',
        ], [
            'title.required' => 'العنوان مطلوب.',
            'content.required' => 'نصّ المعلومة مطلوب.',
            'content.max' => 'النصّ أطول من ' . WaKnowledgeEntry::MAX_CONTENT . ' حرف — قسّمه لمعلومتين.',
        ]);

        $data['title'] = trim($data['title']);
        $data['content'] = trim($data['content']);
        $data['is_active'] = $request->boolean('is_active', true);

        if ($data['is_active']
            && WaKnowledgeEntry::activeChars($exceptId) + mb_strlen($data['title']) + mb_strlen($data['content']) > WaKnowledgeEntry::MAX_TOTAL) {
            back()->withErrors(['content' => 'قاعدة المعرفة ممتلئة: الحدّ ' . WaKnowledgeEntry::MAX_TOTAL
                . ' حرف لأنها تُرسل مع كل رسالة. اختصر معلومات أخرى أو أوقفها.'])->throwResponse();
        }

        return $data;
    }
}
