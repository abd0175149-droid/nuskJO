<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Support\Facades\DB;

/**
 * شخصيات صندوق الواتساب في الاختبارات.
 * لكل شخصية دور باسمها: withPermissions يضيف إلى الدور ولا يستبدل، فدور مشترك يسرّب الصلاحيات بين الشخصيات.
 */
trait BuildsWhatsAppStaff
{
    /** صلاحيات مسارات المحادثة السبعة كلها، بلا view_all ولا admin_chats */
    protected const INBOX_PERMISSIONS = ['whatsapp.view', 'whatsapp.reply', 'whatsapp.bot_toggle', 'whatsapp.assign', 'whatsapp.link_client'];

    protected function staffWith(string $role, array $permissions, array $topics = [], bool $primary = false): User
    {
        $user = User::factory()->withPermissions($permissions, $role)->create();
        foreach ($topics as $topic) {
            DB::table('wa_topic_users')->insert([
                'topic' => $topic, 'user_id' => $user->id, 'is_primary' => $primary,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    protected function conversationTagged(array $topics = [], array $attributes = []): WaConversation
    {
        $conversation = WaConversation::factory()->windowOpen()->create($attributes);
        foreach ($topics as $topic) {
            DB::table('wa_conversation_topics')->insert([
                'conversation_id' => $conversation->id, 'topic' => $topic, 'source' => 'manual',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $conversation;
    }

    /** كل ما قد يغيّره مسار محادثة: يجب أن يبقى كما هو بعد طلب مرفوض */
    protected function conversationState(WaConversation $conversation): array
    {
        return [
            'row' => (array) DB::table('wa_conversations')->where('id', $conversation->id)->first(),
            'topics' => DB::table('wa_conversation_topics')->where('conversation_id', $conversation->id)->orderBy('topic')->pluck('topic')->all(),
            'messages' => DB::table('wa_messages')->where('conversation_id', $conversation->id)->count(),
        ];
    }
}
