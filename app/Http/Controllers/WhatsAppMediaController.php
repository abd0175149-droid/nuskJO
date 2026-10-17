<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Jobs\ArchiveWhatsAppMedia;
use App\Models\AuditLog;
use App\Models\WaMessage;
use App\Services\WhatsApp\Media\MediaArchiver;
use App\Services\WhatsApp\Media\MediaName;
use App\Services\WhatsApp\Media\MediaPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * مرفقات العملاء المؤرشفة (E14.6/E14.8): الرسالة تصل عبر ربط محصور بنطاق المحادثة (404 خارجه)، والعرض لمن يملك
 * whatsapp.view وwhatsapp.media. الرد attachment دائماً بترويسات تمنع التنفيذ والتخزين، وكل اطّلاع في التدقيق.
 */
class WhatsAppMediaController extends Controller
{
    /** أنواع تُخدم بنوعها (لا تنفّذ شيئاً في المتصفح)، وما سواها octet-stream */
    private const INERT = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4', 'video/3gpp',
        'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr'];

    public function show(Request $request, WaMessage $message)
    {
        $user = $request->user();
        abort_unless($user->can('whatsapp.view') && $user->can('whatsapp.media'), 403);
        abort_unless($message->media_status === 'stored' && $message->media_path, 404);

        $disk = MediaArchiver::disk();
        if (!$disk->exists($message->media_path)) {
            $message->update(['media_status' => 'missing']);
            abort(404);
        }

        $download = $request->boolean('download');
        $changes = ['conv' => $message->conversation_id, 'mime' => $message->media_mime, 'bytes' => $message->media_size];
        if ($download) {
            AuditLog::safe(AuditAction::WaMediaDownload, 'wa_message', $message->id, null, $changes);
        } elseif (Cache::add("wa:media:view:{$user->id}:{$message->id}", 1, 600)) {
            // العارض: صف لكل موظف ورسالة كل 10 دقائق (التكبير والتدوير لا يكرّران الطلب، والفتح ثانية لا يُغرق السجل)
            AuditLog::safe(AuditAction::WaMediaView, 'wa_message', $message->id, null, $changes);
        }

        $type = in_array($message->media_mime, self::INERT, true) ? $message->media_mime : 'application/octet-stream';
        $headers = [
            'Content-Type' => $type,
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', MediaName::safe($message), MediaName::ascii($message)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ];

        // الصوت (E34): من القرص المحلي بدعم Range، فيعرف مشغّل المتصفح مدة ogg ويتنقل فيه. الترويسات نفسها
        if (str_starts_with($type, 'audio/') && config('filesystems.disks.'.config('whatsapp.media.disk').'.driver') === 'local') {
            return new BinaryFileResponse($disk->path($message->media_path), 200, $headers, false, null, false, false);
        }

        return $disk->response($message->media_path, MediaName::safe($message), $headers, 'attachment');
    }

    /** تنزيل يدوي حتى 100MB لملف كبير أو فشل، خلال صلاحية معرّفه عند ميتا */
    public function refetch(Request $request, WaMessage $message, MediaArchiver $archiver)
    {
        $user = $request->user();
        abort_unless($user->can('whatsapp.media') && $user->can('whatsapp.reply'), 403);

        if (!in_array($message->media_status, ['too_large', 'failed'], true) || !$message->media_id) {
            return response()->json(['message' => 'لا يمكن تنزيل هذا الملف يدوياً (حُذف أو انتهى أو نوعه غير مسموح).'], 422);
        }
        if (!MediaPresenter::withinIdLifetime($message)) {
            return response()->json(['message' => 'انتهت صلاحية الملف عند ميتا — اطلب من العميل إرساله ثانية.'], 422);
        }
        if ($archiver->overQuota()) {
            return response()->json(['message' => 'أرشيف المرفقات بلغ سقفه — أبلغ المدير.'], 422);
        }
        $key = 'media-refetch:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'محاولات كثيرة — انتظر دقيقة.'], 429);
        }
        RateLimiter::hit($key, 60);

        $cap = (int) config('whatsapp.media.manual_max_bytes');
        $message->update(['media_status' => 'pending', 'media_error' => null]);
        ArchiveWhatsAppMedia::dispatch($message->id, $cap);
        AuditLog::safe(AuditAction::WaMediaRefetch, 'wa_message', $message->id, null, ['conv' => $message->conversation_id, 'cap_mb' => intdiv($cap, 1024 * 1024)]);

        return response()->json(['ok' => true, 'media' => MediaPresenter::forThread($message->fresh(), $user)]);
    }
}
