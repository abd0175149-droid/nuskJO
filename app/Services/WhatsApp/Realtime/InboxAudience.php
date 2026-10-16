<?php

namespace App\Services\WhatsApp\Realtime;

use App\Models\User;
use App\Models\WaConversation;
use App\Services\WhatsApp\TopicRouter;
use Illuminate\Support\Facades\DB;

/**
 * من يرى المحادثة الآن (E28): المستلمون لحدث البث، بقواعد سياج E04 نفسها (TopicRouter::scopeVisible وأسواره الثلاثة):
 * - موظف نشط يملك whatsapp.view (المسارات تفرضها)؛
 * - محادثة الإدارة لمن يملك whatsapp.admin_chats وحده، بالقيد نفسه في SQL (TopicRouter::isAdminChat)؛
 * - الأرقام المسموحة (allowedNumberIds)؛
 * - ثم view_all، أو التوكيل، أو وسم من اختصاصه.
 * صيغة مجمّعة (نحو ثلاثة استعلامات للمحادثة، والمرشحون مرة للدفعة) بدل canSee لكل مستخدم، وبلا كاش: السحب فوري.
 * أي تغيير في السياج يتبعه تغيير هنا، ويفرضه InboxAudienceParityTest مقابل canSee.
 */
final class InboxAudience
{
    /** @return list<int> */
    public static function for(WaConversation $conversation): array
    {
        return self::forMany([$conversation])[$conversation->id] ?? [];
    }

    /**
     * @param  iterable<WaConversation>  $conversations
     * @return array<int, list<int>> رقم المحادثة => المستخدمون
     */
    public static function forMany(iterable $conversations): array
    {
        $candidates = null;
        $out = [];
        foreach ($conversations as $conversation) {
            $candidates ??= self::candidates();
            $out[$conversation->id] = self::pick($conversation, $candidates);
        }

        return $out;
    }

    /** @param list<array{id: int, all: bool, admin: bool, numbers: ?array}> $candidates */
    private static function pick(WaConversation $conversation, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $adminChat = TopicRouter::isAdminChat($conversation);
        $topics = DB::table('wa_conversation_topics')->where('conversation_id', $conversation->id)->pluck('topic')->all();
        $specialists = $topics
            ? array_flip(DB::table('wa_topic_users')->whereIn('topic', $topics)->pluck('user_id')->map(fn ($id) => (int) $id)->all())
            : [];
        $assignee = $conversation->assigned_to !== null ? (int) $conversation->assigned_to : null;

        $ids = [];
        foreach ($candidates as $u) {
            if ($adminChat && !$u['admin']) {
                continue;
            }
            if ($u['numbers'] !== null && !in_array($conversation->phone_number_id, $u['numbers'], false)) {
                continue;
            }
            if ($u['all'] || $assignee === $u['id'] || isset($specialists[$u['id']])) {
                $ids[] = $u['id'];
            }
        }

        return $ids;
    }

    /**
     * النشطون الذين يملكون whatsapp.view، بقدراتهم من البوابة نفسها (Gate::before: المعطّل مرفوض والمدير مسموح).
     *
     * @return list<array{id: int, all: bool, admin: bool, numbers: ?array}>
     */
    private static function candidates(): array
    {
        return User::query()->where('is_active', true)->with('role.permissions')->get()
            ->filter(fn (User $u) => $u->can('whatsapp.view'))
            ->map(fn (User $u) => [
                'id' => (int) $u->id,
                'all' => $u->can('whatsapp.view_all'),
                'admin' => $u->can('whatsapp.admin_chats'),
                'numbers' => TopicRouter::allowedNumberIds($u),
            ])->values()->all();
    }
}
