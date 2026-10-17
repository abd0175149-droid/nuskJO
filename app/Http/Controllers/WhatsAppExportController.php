<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Services\WhatsApp\ConversationExporter;
use App\Services\WhatsApp\WaLog;
use App\Support\FeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * تصدير محادثة واحدة (E30.6): GET api/whatsapp/conversations/{conversation}/export عبر الربط المقيّد (خارج النطاق
 * ومحادثة إدارة بلا admin_chats = 404)، بصلاحية whatsapp.export وسبب إلزامي، 5 في الدقيقة.
 * صف التدقيق (warn) يُكتب قبل البث وفشله يمنع التصدير؛ وعدد الصفوف الفعلي في سطر سجل بالمعرّفات (التدقيق للإلحاق).
 * اسم الملف بلا هاتف ولا اسم: أسماء الملفات تبقى في سجلات المتصفح والتنزيلات.
 */
class WhatsAppExportController extends Controller
{
    public function export(Request $request, WaConversation $conversation, ConversationExporter $exporter)
    {
        abort_unless(FeatureFlags::on('conversation_export'), 404);
        abort_unless(auth()->user()->can('whatsapp.export'), 403);

        $v = $request->validate([
            'format' => 'required|in:txt,csv',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'reason' => 'required|string|min:5|max:200',
        ], [
            'format.*' => 'اختر الصيغة: نص أو CSV',
            'reason.required' => 'اكتب سبب التصدير',
            'reason.min' => 'سبب التصدير 5 أحرف على الأقل',
            'reason.max' => 'سبب التصدير 200 حرف على الأكثر',
            'to.after_or_equal' => 'نهاية الفترة قبل بدايتها',
        ]);
        [$from, $to] = ConversationExporter::range($v['from'] ?? null, $v['to'] ?? null);

        $rows = $exporter->count($conversation, $from, $to);
        if ($rows > ConversationExporter::maxRows()) {
            return response()->json(['message' => 'المحادثة أطول من '.number_format(ConversationExporter::maxRows()).' رسالة في هذه الفترة: ضيّق الفترة.',
                'rows' => $rows], 422);
        }

        // صارم: لا تصدير بلا صف تدقيق
        $audit = DB::transaction(fn () => AuditLog::log(AuditAction::WaConversationExport, 'wa_conversation', $conversation->id, null, [
            'format' => $v['format'], 'from' => $v['from'] ?? null, 'to' => $v['to'] ?? null,
            'rows_estimate' => $rows, 'reason' => trim($v['reason']),
        ], AuditSeverity::Warn));

        $user = auth()->user();
        $format = $v['format'];

        return response()->streamDownload(function () use ($exporter, $conversation, $format, $from, $to, $user, $audit) {
            $h = fopen('php://output', 'w');
            $n = $exporter->stream($conversation, $format, $from, $to, $h, $user);
            fclose($h);
            WaLog::info('wa.export.done', ['conv_id' => $conversation->id, 'count' => $n, 'type' => $format, 'audit_id' => $audit->id]);
        }, 'wa-conv-'.$conversation->id.'-'.now()->format('Ymd-His').'.'.$format, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
