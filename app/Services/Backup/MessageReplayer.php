<?php

namespace App\Services\Backup;

use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ContactIdentity;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * يعيد إلى القاعدة المستعادة رسائل واتساب التي وصلت بعد وقت النسخة، من لقطة ما قبل الاستعادة.
 * ميتا لا تعيد إرسال ما أخذ 200، ولا API لجلب الوارد، فهذه اللقطة هي المصدر الوحيد.
 *
 * لا ردّ ولا إرسال: إدراج مباشر في wa_messages دون ProcessWhatsAppReply ولا WhatsAppChannel.
 * قابل للتكرار: wamid (أو مفتاح مركّب حين يغيب) يمنع إدراج الرسالة مرتين.
 */
class MessageReplayer
{
    private const CONNECTION = 'backup_replay_source';

    /**
     * @return array{candidates:int,inserted:int,duplicates:int,conversations_created:int,conversations_touched:int}
     */
    public function replay(string $snapshotPath, string $since, bool $dryRun = false): array
    {
        $source = self::open($snapshotPath);
        $sinceUtc = Carbon::parse($since)->utc()->format('Y-m-d H:i:s');
        $label = basename($snapshotPath);

        $rows = $source->table('wa_messages as m')
            ->join('wa_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.created_at', '>', $sinceUtc)
            ->orderBy('m.id')
            ->get(array_merge(['m.*', 'c.wa_phone as c_wa_phone', 'c.display_name as c_display_name', 'c.id as c_id'],
                // لقطات ما بعد E11a: محادثة باسم مستخدم بلا رقم تُعرف بمعرّفها
                $source->getSchemaBuilder()->hasColumn('wa_conversations', 'wa_user_id') ? ['c.wa_user_id as c_wa_user_id'] : []));

        $stats = ['candidates' => $rows->count(), 'inserted' => 0, 'duplicates' => 0, 'conversations_created' => 0, 'conversations_touched' => 0];
        $touched = [];   // معرّف المحادثة الحية => معرّف المحادثة في اللقطة
        $replayedInbound = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                $userId = $row->c_wa_user_id ?? null;
                $phone = ContactIdentity::phoneFrom($row->c_wa_phone);
                if ($phone === null && !filled($userId)) {
                    continue;
                }
                $existed = $phone !== null
                    ? WaConversation::where('phone', $phone)->exists()
                    : WaConversation::where('wa_user_id', $userId)->exists();
                $conv = ContactIdentity::resolveIdentity($row->c_wa_phone, $userId);
                // الاسم المعروض كما كان في النسخة (لا يُكتب فوق اسم موجود)
                if ($row->c_display_name && !$conv->display_name) {
                    $conv->forceFill(['display_name' => \App\Services\WhatsApp\TextNormalizer::untrustedName($row->c_display_name)])->save();
                }
                if (!$existed) {
                    $stats['conversations_created']++;
                }

                if ($this->alreadyPresent($conv->id, $row)) {
                    $stats['duplicates']++;

                    continue;
                }

                $payload = json_decode((string) $row->payload, true);
                $payload = is_array($payload) ? $payload : [];
                $payload['_replayed_from'] = $label;

                $message = new WaMessage([
                    'conversation_id' => $conv->id,
                    'wamid' => $row->wamid,
                    'direction' => $row->direction,
                    'source' => $row->source,
                    'msg_type' => $row->msg_type,
                    'body' => $row->body,
                    'payload' => $payload,
                    'status' => $row->status,
                    'error_message' => $row->error_message,
                    'staff_id' => $row->staff_id,
                ]);
                $message->created_at = $row->created_at;
                $message->updated_at = $row->updated_at;
                $message->save();

                $stats['inserted']++;
                $touched[$conv->id] = $row->c_id;
                if ($row->direction === 'in') {
                    $replayedInbound[$conv->id][] = $row->created_at;
                }
            }

            foreach ($touched as $liveId => $snapshotId) {
                $this->refreshConversation($liveId, $sinceUtc, $replayedInbound[$liveId] ?? []);
                $this->copyTopics($source, (int) $snapshotId, $liveId);
            }
            $stats['conversations_touched'] = count($touched);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            DB::purge(self::CONNECTION);
        }

        return $stats;
    }

    /** اتصال sqlite ثانٍ بملف اللقطة، يُعرَّف وقت التشغيل. */
    public static function open(string $path): Connection
    {
        if (!is_file($path)) {
            throw new BackupFailed('ملف اللقطة غير موجود');
        }

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        return DB::connection(self::CONNECTION);
    }

    private function alreadyPresent(int $conversationId, object $row): bool
    {
        if ($row->wamid) {
            return WaMessage::where('wamid', $row->wamid)->exists();
        }

        // رسائل بلا wamid (فاشلة أو من النظام): مفتاح مركّب
        return WaMessage::where('conversation_id', $conversationId)
            ->where('direction', $row->direction)
            ->where('source', $row->source)
            ->where('body', $row->body)
            ->where('created_at', $row->created_at)
            ->exists();
    }

    /** يعيد حساب ملخص المحادثة بالقواعد نفسها التي يطبقها الويبهوك والإرسال. */
    private function refreshConversation(int $id, string $sinceUtc, array $replayedInboundTimes): void
    {
        $conv = WaConversation::find($id);
        $delivered = WaMessage::where('conversation_id', $id)
            ->where(fn ($q) => $q->where('direction', 'in')->orWhere('status', '!=', 'failed')->orWhereNull('status'));

        $lastIn = (clone $delivered)->where('direction', 'in')->max('created_at');
        $lastOut = (clone $delivered)->where('direction', 'out')->max('created_at');
        $latest = (clone $delivered)->orderByDesc('created_at')->orderByDesc('id')->first();
        $lastStaffOut = (clone $delivered)->where('direction', 'out')->where('source', 'staff')->max('created_at');

        $unansweredReplayed = count(array_filter($replayedInboundTimes, fn ($t) => !$lastStaffOut || $t > $lastStaffOut));

        if ($lastStaffOut && $lastStaffOut > $sinceUtc) {
            // ردّ موظف بعد وقت النسخة: كل ما قبله مقروء
            $unread = $unansweredReplayed;
            $attention = $unansweredReplayed > 0;
        } else {
            $unread = $conv->unread_count + $unansweredReplayed;
            $attention = $conv->needs_attention || $unansweredReplayed > 0;
        }

        $conv->update([
            'last_inbound_at' => $lastIn,
            'last_outbound_at' => $lastOut,
            'last_message_at' => $latest?->created_at,
            'last_message_preview' => $latest ? mb_substr(trim((string) $latest->body), 0, 160) : $conv->last_message_preview,
            'unread_count' => $unread,
            'needs_attention' => $attention,
        ]);
    }

    private function copyTopics(Connection $source, int $snapshotConversationId, int $liveConversationId): void
    {
        if (!$source->getSchemaBuilder()->hasTable('wa_conversation_topics') || !DB::getSchemaBuilder()->hasTable('wa_conversation_topics')) {
            return;
        }

        foreach ($source->table('wa_conversation_topics')->where('conversation_id', $snapshotConversationId)->get() as $topic) {
            DB::table('wa_conversation_topics')->insertOrIgnore([
                'conversation_id' => $liveConversationId,
                'topic' => $topic->topic,
                'source' => $topic->source,
                'created_by' => $topic->created_by,
                'created_at' => $topic->created_at,
                'updated_at' => $topic->updated_at,
            ]);
        }
    }
}
