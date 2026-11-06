<?php

namespace App\Services\WhatsApp\Media;

use App\Models\User;
use App\Models\WaMessage;

/**
 * حقل media لكل رسالة في الخيط (E14.7): النوع والحالة والحجم وما يُسمح للموظف به. لا مسار ولا بصمة ولا معرّف ميتا،
 * والاسم لمن يملك whatsapp.media وحده.
 */
final class MediaPresenter
{
    private const KINDS = ['image', 'document', 'video', 'audio', 'sticker'];

    public static function forThread(WaMessage $m, User $user): ?array
    {
        $status = $m->media_status;
        if (!$m->media_mime && !$status) {
            return null;
        }
        $canMedia = $user->can('whatsapp.media');

        return [
            'kind' => in_array($m->msg_type, self::KINDS, true) ? $m->msg_type : 'document',
            'status' => $status,
            'size' => $m->media_size !== null ? (int) $m->media_size : null,
            'mime' => $m->media_mime,
            'name' => $canMedia ? ($status === 'stored' ? MediaName::safe($m) : $m->media_filename) : null,
            'error' => in_array($status, ['failed', 'rejected'], true) ? $m->media_error : null,
            'viewable' => $status === 'stored' && $canMedia,
            'previewable' => (bool) (MediaArchiver::allowedTypes()[(string) $m->media_mime][1] ?? false),
            'refetchable' => self::refetchable($m, $user),
        ] + ($m->media_remote_deleted_at !== null ? ['remote_deleted' => true] : []);
    }

    /**
     * تنزيل يدوي ممكن: كبير أو فشل ومعرّفه لم ينتهِ، أو من السجل المستورد (remote: الملف على الهاتف بلا مهلة نعرفها)،
     * ولمن يملك العرض والرد
     */
    public static function refetchable(WaMessage $m, User $user): bool
    {
        $remote = $m->media_status === 'remote';

        return ($remote || in_array($m->media_status, ['too_large', 'failed'], true)) && $m->media_id && $m->media_error !== (string) MediaIntake::META_TOO_LARGE
            && ($remote || self::withinIdLifetime($m)) && $user->can('whatsapp.media') && $user->can('whatsapp.reply');
    }

    public static function withinIdLifetime(WaMessage $m): bool
    {
        return (bool) $m->created_at?->gt(now()->subMinutes((int) config('whatsapp.media.id_lifetime_minutes')));
    }
}
