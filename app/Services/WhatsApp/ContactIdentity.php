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
 * هوية العميل في الويبهوك (E11a ثم E11): معرّف ميتا الخاص بنشاطنا (BSUID، from_user_id) أولاً، ثم الرقم الكامل.
 * عميل فعّل اسم مستخدم ولم نتواصل معه 30 يوماً يصل بلا from ولا wa_id، فتُعرف محادثته بالمعرّف وحده.
 *
 * - لا يُربط عميل ولا موظف ولا رقم إدارة إلا بالرقم القادم من ويبهوك موقّع؛ المعرّف والاسم لا يكفيان.
 * - الرقم والمعرّف في الويبهوك الموقّع نفسه لمحادثتين: تُدمج محادثة المعرّف في محادثة الرقم (إلا لعميلين مختلفين).
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

    /** مدخل contacts الخاص بهذه الرسالة: بالمعرّف، ثم بالرقم، ثم الوحيد إن كان واحداً */
    public static function contactFor(array $contacts, ?string $from, ?string $userId): array
    {
        foreach ([['user_id', $userId], ['wa_id', $from]] as [$key, $value]) {
            if ($value !== null && $value !== '') {
                foreach ($contacts as $contact) {
                    if ((string) data_get($contact, $key) === $value) {
                        return (array) $contact;
                    }
                }
            }
        }

        return count($contacts) === 1 ? (array) reset($contacts) : [];
    }

    /** محادثة الرسالة، تُنشأ إن لم توجد. يُستدعى داخل معاملة التخزين */
    public static function resolve(InboundMessage $in): WaConversation
    {
        return self::resolveIdentity($in->fromPhone, $in->fromUserId, $in->profileName, $in->username, $in->phoneNumberId);
    }

    /** (MessageReplayer والاختبارات؛ الويبهوك عبر resolve) */
    public static function resolveIdentity(?string $from, ?string $userId, ?string $name = null, ?string $username = null, ?string $pnid = null): WaConversation
    {
        $phone = self::phoneFrom($from);
        $userId = filled($userId) ? mb_substr((string) $userId, 0, 160) : null;

        if ($phone === null && $userId === null) {
            throw new \InvalidArgumentException('WA_NO_IDENTITY');
        }

        $byPhone = $phone ? WaConversation::where('phone', $phone)->where('status', '!=', 'merged')->first() : null;
        $byId = $userId ? WaConversation::where('wa_user_id', $userId)->where('status', '!=', 'merged')->first() : null;

        if ($byPhone && $byId && $byPhone->id !== $byId->id) {
            // ميتا أرسلت الرقم والمعرّف معاً في ويبهوك موقّع: محادثة واحدة. عميلان مختلفان = لا دمج آلي
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
                $phone ? ['phone' => $phone] : ['wa_user_id' => $userId],
                [
                    'wa_phone' => $phone ? PhoneNormalizer::digits($from) : null,
                    'wa_user_id' => $userId,
                    'phone_number_id' => $pnid ?: null,
                    'bot_enabled' => true,
                    'status' => 'open',
                ],
            );
        }

        $update = [];
        // الناقص يُملأ إن لم تحجزه محادثة أخرى
        if ($phone && !$conv->hasPhone() && !self::phoneTaken($phone, $conv->id)) {
            $update['phone'] = $phone;
            $update['wa_phone'] = PhoneNormalizer::digits($from);
        }
        // معرّف جديد مع الرقم نفسه (ميتا تجدّده حين يغيّر العميل رقمه): يُحدَّث
        if ($userId && $conv->wa_user_id !== $userId && (!$conv->wa_user_id || $byPhone?->is($conv))
            && !WaConversation::where('wa_user_id', $userId)->whereKeyNot($conv->id)->exists()) {
            $update['wa_user_id'] = $userId;
        }
        // وُجدت بالمعرّف ورقمها غير رقم الرسالة: لا تغيير للرقم، وشذوذ يراه موظف
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

    private static function phoneTaken(string $phone, int $exceptId): bool
    {
        return WaConversation::where('phone', $phone)->whereKeyNot($exceptId)->where('status', '!=', 'merged')->exists();
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

            // المعرّف فريد: يُصفَّر في A قبل كتابته في B
            $userId = $a->wa_user_id;
            DB::table('wa_conversations')->where('id', $a->id)->update(['wa_user_id' => null]);
            $latest = fn (string $col) => max((string) $a->getRawOriginal($col), (string) $b->getRawOriginal($col)) ?: null;
            DB::table('wa_conversations')->where('id', $b->id)->update(array_filter([
                'wa_user_id' => $b->wa_user_id ?: $userId,
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
                'note' => "دُمجت محادثة #{$a->id} هنا: أرسلت ميتا رقم العميل ومعرّفه معاً.", 'source' => 'system', 'created_at' => now()]);
            AuditActor::as('meta', 'identity', fn () => AuditLog::log(AuditAction::WaMerge, 'wa_conversation', $b->id, null, ['from_id' => $a->id, 'reason' => 'same_webhook_phone_bsuid']));
        });
        WaLog::warning('identity.merged', ['conv_id' => $b->id, 'count' => $a->id]);
    }

    /**
     * رسالة نظام من ميتا (غيّر العميل رقمه أو تغيّر معرّفه): تُحفظ في محادثته الموجودة ولا تُنشأ محادثة،
     * ولا تفتح النافذة، ولا يرد عليها البوت. العميل المربوط يبقى (ميتا تشهد أنه الحساب نفسه)، ويُرفع «تدخّل».
     */
    public static function applySystemChange(InboundMessage $in): ?WaConversation
    {
        $phone = self::phoneFrom($in->fromPhone);
        $conv = ($phone ? WaConversation::where('phone', $phone)->where('status', '!=', 'merged')->first() : null)
            ?? ($in->fromUserId ? WaConversation::where('wa_user_id', $in->fromUserId)->where('status', '!=', 'merged')->first() : null);
        if (!$conv) {
            WaLog::warning('identity.system_without_conversation', ['wamid' => $in->wamid, 'type' => $in->system['type'] ?? null]);

            return null;
        }

        $stored = WaMessage::create([
            'conversation_id' => $conv->id, 'wamid' => $in->wamid, 'direction' => 'in', 'source' => 'system',
            'msg_type' => 'system', 'body' => $in->body, 'phone_number_id' => $in->phoneNumberId ?: null,
            'payload' => ['system' => $in->system],
        ]);
        // بعد اعتماد معاملة المستدعي وحدها (E21)
        EventBus::emit(new \App\Events\WhatsApp\WaMessageStored($stored->id, $conv->id, 'system'));

        $update = [];
        $note = null;
        $newPhone = self::phoneFrom($in->system['newPhone'] ?? null);
        if ($newPhone && $newPhone !== $conv->phone) {
            if (self::phoneTaken($newPhone, $conv->id)) {
                $note = 'أبلغت ميتا أن العميل غيّر رقمه، لكن الرقم الجديد لمحادثة أخرى — راجع المحادثتين.';
            } else {
                $update += ['phone' => $newPhone, 'wa_phone' => PhoneNormalizer::digits($in->system['newPhone'])];
                $note = 'غيّر العميل رقمه — حدّث رقمه في ملف العميل بعد التأكد.';
            }
        }
        $newUserId = $in->system['newUserId'] ?? null;
        if ($newUserId && $newUserId !== $conv->wa_user_id && !WaConversation::where('wa_user_id', $newUserId)->whereKeyNot($conv->id)->exists()) {
            $update['wa_user_id'] = $newUserId;
            $note ??= 'تغيّر معرّف واتساب للعميل.';
        }

        $conv->forceFill($update + ['needs_attention' => true] + (ReplyState::available() ? ['attention_reason' => 'identity_change'] : []))->save();
        WaCustomerNote::create(['conversation_id' => $conv->id, 'client_id' => $conv->client_id, 'note' => $note ?? $in->body, 'source' => 'system', 'created_at' => now()]);
        AuditActor::as('meta', 'system', fn () => AuditLog::safe(AuditAction::WaIdentityChange, 'wa_conversation', $conv->id, null, [
            'system_type' => $in->system['type'] ?? null, 'phone_changed' => isset($update['phone']), 'user_id_changed' => isset($update['wa_user_id']),
        ]));

        $title = '🔄 تغيّر رقم عميل واتساب';
        // نص ميتا يحمل الرقمين القديم والجديد: يبقى في ملاحظة المحادثة لا في الجرس (E15.10)
        $body = $conv->noticeLabel().' — غيّر رقمه أو معرّفه في واتساب. التفاصيل في المحادثة.';
        if ($conv->assigned_to) {
            rescue(fn () => \App\Services\NotificationService::send((int) $conv->assigned_to, $title, $body, ['type' => 'whatsapp', 'icon' => '💬', 'action_url' => '/whatsapp/inbox?conv='.$conv->id]), null, false);
        }
        WhatsAppInbox::notifyAdmins($title, $body, '/whatsapp/inbox?conv='.$conv->id);

        return $conv;
    }

    /** حقل user_id_update (إن وصل): المعرّف السابق أو الرقم ← المعرّف الحالي، إن لم يكن لمحادثة أخرى */
    public static function applyUserIdUpdate(array $value): void
    {
        foreach ((array) data_get($value, 'user_id_update', [$value]) as $item) {
            $previous = data_get($item, 'user_id.previous') ?? data_get($item, 'previous_user_id');
            $current = data_get($item, 'user_id.current') ?? data_get($item, 'user_id');
            if (!is_string($current) || !preg_match(InboundMessage::BSUID, $current) || str_contains($current, '.ENT.')) {
                continue;
            }
            $phone = self::phoneFrom(data_get($item, 'wa_id'));
            $conv = (is_string($previous) ? WaConversation::where('wa_user_id', $previous)->first() : null)
                ?? ($phone ? WaConversation::where('phone', $phone)->first() : null);
            if ($conv && !WaConversation::where('wa_user_id', $current)->whereKeyNot($conv->id)->exists()) {
                $conv->forceFill(['wa_user_id' => mb_substr($current, 0, 160)])->save();
            }
        }
    }

    /** حالة تسليم فيها الرقم والمعرّف معاً لمحادثة رقمها معروف ومعرّفها فارغ: يُملأ المعرّف */
    public static function fillFromStatus(array $status, WaConversation $conv): void
    {
        $phone = self::phoneFrom(data_get($status, 'recipient_id'));
        $userId = data_get($status, 'recipient_user_id');
        if ($phone && is_string($userId) && $userId !== '' && $phone === $conv->phone && !$conv->wa_user_id
            && !WaConversation::where('wa_user_id', $userId)->whereKeyNot($conv->id)->exists()) {
            $conv->forceFill(['wa_user_id' => mb_substr($userId, 0, 160)])->save();
        }
    }

    /**
     * رقم شاركه العميل نفسه بزر «طلب الرقم» (origin=contact_request): يملأ رقم المحادثة ثم الربط الآلي.
     * بطاقة شخص ثالث (origin=other) لا تمس الهوية أبداً.
     */
    public static function acceptSharedPhone(WaConversation $conv, InboundMessage $in): void
    {
        $phone = self::phoneFrom($in->sharedPhone);
        if ($in->sharedOrigin !== 'contact_request' || !$phone || $conv->hasPhone() || self::phoneTaken($phone, $conv->id)) {
            return;
        }
        $update = ['phone' => $phone, 'wa_phone' => PhoneNormalizer::digits($in->sharedPhone)];
        $conv->update($update + self::autoLink($conv, $phone));
        WaLog::warning('identity.shared_phone', ['conv_id' => $conv->id]);
    }

    /** الرقم في محادثة والمعرّف في أخرى لعميلين مختلفين: لا دمج، والاثنتان «تدخّل» */
    private static function conflict(WaConversation $byPhone, WaConversation $byId): void
    {
        WaLog::warning('identity.conflict', ['conv_id' => $byPhone->id, 'count' => $byId->id]);
        foreach ([$byPhone, $byId] as $c) {
            $c->forceFill(['needs_attention' => true] + (ReplyState::available() ? ['attention_reason' => 'identity_conflict'] : []))->save();
        }
        if (Cache::add("wa:identity:conflict:{$byPhone->id}:{$byId->id}", 1, now()->addDay())) {
            WhatsAppInbox::notifyAdmins('⚠️ محادثتان لعميل واحد', "المحادثتان #{$byPhone->id} و#{$byId->id} لعميل واحد حسب ميتا، ومربوطتان بعميلين مختلفين. الرسائل الجديدة تذهب إلى #{$byPhone->id}.");
        }
    }
}
