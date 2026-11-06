<?php

namespace App\Services\WhatsApp;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\WaConversation;
use App\Models\WaCustomerNote;
use App\Models\WaMessage;
use App\Support\AuditActor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * هوية العميل في webhook البوابة (2026-11-06): المحادثة لرقم من أرقامنا (channel_id)، وتُعرف عليه بالهاتف الكامل
 * (senderPhone أو jid ‎@c.us) ثم بمعرّف الخصوصية (lid) لمرسل خلفه لم يُعرف رقمه بعد.
 *
 * - لا يُربط عميل ولا موظف ولا رقم إدارة إلا بالهاتف القادم من webhook موقّع؛ المعرّف والاسم لا يكفيان.
 * - الهاتف والمعرّف في الحمولة الموقّعة نفسها لمحادثتين: تُدمج محادثة المعرّف في محادثة الهاتف (إلا لعميلين مختلفين).
 * - الاسم واسم المستخدم كما أرسلهما العميل (غير موثوقين): في wa_profile_name/wa_username للعرض فقط، ولا مطابقة بهما،
 *   ولا يُكتب فوق display_name.
 */
final class ContactIdentity
{
    /** رقم من ميتا: أرقام فقط (المعرّف بصيغة «JO.xxxx» لا يصلح رقماً أبداً) */
    public static function phoneFrom(mixed $from): ?string
    {
        $digits = is_string($from) ? preg_replace('/[\s+\-()]/', '', $from) : null;
        if ($digits === null || !preg_match('/^\d{6,20}$/', $digits)) {
            return null;
        }
        $canonical = PhoneNormalizer::canonical($digits);

        return $canonical !== '' ? $canonical : null;
    }

    /** محادثة الرسالة على رقمها، تُنشأ إن لم توجد. يُستدعى داخل معاملة التخزين */
    public static function resolve(InboundMessage $in): WaConversation
    {
        return self::resolveIdentity($in->fromPhone, $in->fromUserId, $in->profileName, $in->username, $in->phoneNumberId,
            channelId: $in->channelId, jid: $in->jid, lid: $in->lid);
    }

    /** محادثة قائمة على الرقم بالرقم الكامل ثم بمعرّف الخصوصية، بلا إنشاء ولا تعديل */
    public static function find(?string $from, ?string $lid, ?int $channelId = null): ?WaConversation
    {
        $scope = fn () => WaConversation::query()->where('status', '!=', 'merged')
            ->when($channelId !== null, fn ($q) => $q->where('channel_id', $channelId));
        $phone = self::phoneFrom($from);
        if ($phone && ($conv = $scope()->where('phone', $phone)->first())) {
            return $conv;
        }

        return filled($lid) ? $scope()->where('lid', mb_substr((string) $lid, 0, 120))->first() : null;
    }

    /**
     * (المحلل والاسترجاع والاختبارات ورسائل النظام). المحادثة لرقم من أرقامنا (channelId): عميل يراسل رقمين = محادثتان.
     * البحث على الرقم نفسه: بالهاتف الكامل، ثم بمعرّف الخصوصية (lid)، ثم بمعرّف ميتا القديم (BSUID) إن وصل.
     */
    public static function resolveIdentity(?string $from, ?string $userId, ?string $name = null, ?string $username = null, ?string $pnid = null,
        ?int $channelId = null, ?string $jid = null, ?string $lid = null): WaConversation
    {
        $phone = self::phoneFrom($from);
        $userId = filled($userId) ? mb_substr((string) $userId, 0, 160) : null;
        $lid = filled($lid) ? mb_substr((string) $lid, 0, 120) : null;
        $jid = filled($jid) ? mb_substr((string) $jid, 0, 120) : null;

        if ($phone === null && $userId === null && $lid === null) {
            throw new \InvalidArgumentException('WA_NO_IDENTITY');
        }

        $scope = fn () => WaConversation::query()->where('status', '!=', 'merged')
            ->when($channelId !== null, fn ($q) => $q->where('channel_id', $channelId), fn ($q) => $q->whereNull('channel_id'));
        $byPhone = $phone ? $scope()->where('phone', $phone)->first() : null;
        $byId = $lid ? $scope()->where('lid', $lid)->first() : ($userId ? $scope()->where('wa_user_id', $userId)->first() : null);

        if ($byPhone && $byId && $byPhone->id !== $byId->id) {
            // الهاتف ومعرّف الخصوصية معاً في حمولة موقّعة: محادثة واحدة. عميلان مختلفان = لا دمج آلي
            if ($byPhone->client_id && $byId->client_id && $byPhone->client_id !== $byId->client_id) {
                self::conflict($byPhone, $byId);
            } else {
                self::mergeInto($byId, $byPhone);
                $byPhone->refresh();
                $byId = null;
            }
        }
        $conv = $byPhone ?? $byId;

        if (!$conv) {
            $conv = WaConversation::createOrFirst(
                ['channel_id' => $channelId] + ($phone ? ['phone' => $phone] : ($lid ? ['lid' => $lid] : ['wa_user_id' => $userId])),
                [
                    'wa_phone' => $phone ? PhoneNormalizer::digits($from) : null,
                    'wa_user_id' => $userId,
                    'jid' => $jid ?? ($phone ? PhoneNormalizer::digits($from).'@c.us' : $lid),
                    'lid' => $lid,
                    'phone_number_id' => $pnid ?: null,
                    'bot_enabled' => true,
                    'status' => 'open',
                ],
            );
        }

        $update = [];
        // الناقص يُملأ إن لم تحجزه محادثة أخرى على الرقم نفسه
        if ($phone && !$conv->hasPhone() && !self::phoneTaken($phone, $conv->id, $channelId)) {
            $update['phone'] = $phone;
            $update['wa_phone'] = PhoneNormalizer::digits($from);
        }
        if ($lid && $conv->lid !== $lid && !$scope()->where('lid', $lid)->whereKeyNot($conv->id)->exists()) {
            $update['lid'] = $lid;
        }
        // jid آخر ما راسَلنا منه العميل: الرد إليه
        if ($jid && $conv->jid !== $jid) {
            $update['jid'] = $jid;
        }
        if ($userId && !$conv->wa_user_id) {
            $update['wa_user_id'] = $userId;
        }
        // وُجدت بمعرّف الخصوصية ورقمها غير رقم الرسالة: لا تغيير للرقم، وشذوذ يراه موظف
        if ($phone && $conv->hasPhone() && $conv->phone !== $phone && !$byPhone) {
            WaLog::warning('identity.phone_mismatch', ['conv_id' => $conv->id]);
            $update['needs_attention'] = true;
        }
        if ($pnid && !$conv->phone_number_id) {
            $update['phone_number_id'] = $pnid;
        }
        if ($name !== null && $conv->wa_profile_name !== $name) {
            $update['wa_profile_name'] = $name;
        }
        if ($username !== null && $conv->wa_username !== $username) {
            $update['wa_username'] = $username;
        }

        $update += self::autoLink($conv, $update['phone'] ?? $conv->phone);

        if ($update) {
            $conv->update($update);
        }

        return $conv->refresh();
    }

    /**
     * الربط الآلي بالعميل وبالموظف: بالرقم الكامل وحده، وما لم يربطه أو يمنعه موظف يدوياً.
     *
     * @return array<string, mixed>
     */
    private static function autoLink(WaConversation $conv, ?string $phone): array
    {
        if (!filled($phone)) {
            return [];
        }
        $update = [];
        if (!$conv->client_id && !in_array($conv->client_link, ['manual', 'blocked'], true) && ($client = PhoneNormalizer::findClient($phone))) {
            $update['client_id'] = $client->id;
            $update['client_link'] = 'auto';
            $update['client_linked_at'] = now();
        }
        if (!$conv->user_id && ($user = PhoneNormalizer::findUser($phone))) {
            $update['user_id'] = $user->id;
        }

        return $update;
    }

    private static function phoneTaken(string $phone, int $exceptId, ?int $channelId = null): bool
    {
        $channelId ??= WaConversation::whereKey($exceptId)->value('channel_id');

        return WaConversation::where('phone', $phone)->whereKeyNot($exceptId)->where('status', '!=', 'merged')
            ->when($channelId !== null, fn ($q) => $q->where('channel_id', $channelId), fn ($q) => $q->whereNull('channel_id'))->exists();
    }

    /**
     * دمج A في B (المعرّف في محادثة الرقم): كل ما يشير إلى A ينتقل إلى B، وA تبقى «merged» تشير إلى B.
     * لا يُحذف صف، فالدمج قابل للتتبع والعكس يدوياً.
     */
    public static function mergeInto(WaConversation $a, WaConversation $b): void
    {
        DB::transaction(function () use ($a, $b) {
            foreach (['wa_messages', 'wa_customer_notes', 'quote_requests', 'wa_bot_usage', 'wa_admin_actions'] as $table) {
                DB::table($table)->where('conversation_id', $a->id)->update(['conversation_id' => $b->id]);
            }
            foreach (DB::table('wa_conversation_topics')->where('conversation_id', $a->id)->get() as $tag) {
                DB::table('wa_conversation_topics')->insertOrIgnore(['conversation_id' => $b->id] + array_diff_key((array) $tag, ['id' => 0, 'conversation_id' => 0]));
            }
            DB::table('wa_conversation_topics')->where('conversation_id', $a->id)->delete();
            // التصنيفات والتثبيت داخل الخيط (E29) تنتقل مع الرسائل
            LabelService::moveConversation($a->id, $b->id);
            ThreadPins::moveConversation($a->id, $b->id);

            // المعرّفان فريدان على الرقم: يُصفَّران في A قبل كتابتهما في B
            $userId = $a->wa_user_id;
            $lid = $a->lid;
            DB::table('wa_conversations')->where('id', $a->id)->update(['wa_user_id' => null, 'lid' => null]);
            $latest = fn (string $col) => max((string) $a->getRawOriginal($col), (string) $b->getRawOriginal($col)) ?: null;
            DB::table('wa_conversations')->where('id', $b->id)->update(array_filter([
                'wa_user_id' => $b->wa_user_id ?: $userId,
                'lid' => $b->lid ?: $lid,
                'wa_username' => $b->wa_username ?: $a->wa_username,
                'wa_profile_name' => $b->wa_profile_name ?: $a->wa_profile_name,
                'client_id' => $b->client_id ?: $a->client_id,
                'assigned_to' => $b->assigned_to ?: $a->assigned_to,
                'referral' => $b->getRawOriginal('referral') ?: $a->getRawOriginal('referral'),
                'last_inbound_at' => $latest('last_inbound_at'),
                'last_message_at' => $latest('last_message_at'),
            ], fn ($v) => $v !== null) + [
                'unread_count' => (int) $a->unread_count + (int) $b->unread_count,
                'needs_attention' => $a->needs_attention || $b->needs_attention,
                'bot_enabled' => $a->bot_enabled && $b->bot_enabled,
            ] + (ReplyState::available() ? ['bot_answered_in_id' => max((int) $a->bot_answered_in_id, (int) $b->bot_answered_in_id)] : []));
            DB::table('wa_conversations')->where('id', $a->id)->update(['status' => 'merged', 'merged_into_id' => $b->id, 'needs_attention' => false, 'unread_count' => 0]);

            WaCustomerNote::create(['conversation_id' => $b->id, 'client_id' => $b->client_id ?: $a->client_id,
                'note' => "دُمجت محادثة #{$a->id} هنا: وصل رقم العميل ومعرّفه معاً.", 'source' => 'system', 'created_at' => now()]);
            AuditActor::as('meta', 'identity', fn () => AuditLog::log(AuditAction::WaMerge, 'wa_conversation', $b->id, null, ['from_id' => $a->id, 'reason' => 'same_webhook_phone_lid']));
        });
        WaLog::warning('identity.merged', ['conv_id' => $b->id, 'count' => $a->id]);
    }

    /** الرقم في محادثة والمعرّف في أخرى لعميلين مختلفين: لا دمج، والاثنتان «تدخّل» */
    private static function conflict(WaConversation $byPhone, WaConversation $byId): void
    {
        WaLog::warning('identity.conflict', ['conv_id' => $byPhone->id, 'count' => $byId->id]);
        foreach ([$byPhone, $byId] as $c) {
            $c->forceFill(['needs_attention' => true] + (ReplyState::available() ? ['attention_reason' => 'identity_conflict'] : []))->save();
        }
        if (Cache::add("wa:identity:conflict:{$byPhone->id}:{$byId->id}", 1, now()->addDay())) {
            WhatsAppInbox::notifyAdmins('⚠️ محادثتان لعميل واحد', "المحادثتان #{$byPhone->id} و#{$byId->id} لعميل واحد حسب واتساب، ومربوطتان بعميلين مختلفين. الرسائل الجديدة تذهب إلى #{$byPhone->id}.");
        }
    }
}
