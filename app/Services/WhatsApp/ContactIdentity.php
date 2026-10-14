<?php

namespace App\Services\WhatsApp;

use App\Models\WaConversation;
use Illuminate\Support\Facades\Cache;

/**
 * هوية العميل في الويبهوك (E11a): معرّف ميتا الخاص بنشاطنا (BSUID، from_user_id) أولاً، ثم الرقم الكامل.
 * عميل فعّل اسم مستخدم ولم نتواصل معه 30 يوماً يصل بلا from ولا wa_id، فتُعرف محادثته بالمعرّف وحده.
 *
 * - لا يُربط عميل ولا موظف ولا رقم إدارة إلا بالرقم القادم من ويبهوك موقّع؛ المعرّف والاسم لا يكفيان.
 * - الرقم والمعرّف معاً لمحادثتين مختلفتين: الدمج في E11؛ هنا تُستعمل محادثة الرقم، والاثنتان «تدخّل».
 * - الاسم واسم المستخدم كما أرسلهما العميل (غير موثوقين): للعرض فقط، ولا مطابقة بهما.
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

    /**
     * محادثة الرسالة، تُنشأ إن لم توجد. يُستدعى داخل معاملة التخزين.
     */
    public static function resolve(?string $from, ?string $userId, array $contact = []): WaConversation
    {
        $phone = self::phoneFrom($from);
        $userId = filled($userId) ? mb_substr((string) $userId, 0, 160) : null;
        $name = self::untrusted(data_get($contact, 'profile.name'), 120);
        $username = self::untrusted(data_get($contact, 'profile.username'), 100);

        if ($phone === null && $userId === null) {
            throw new \InvalidArgumentException('WA_NO_IDENTITY');
        }

        $byPhone = $phone ? WaConversation::where('phone', $phone)->first() : null;
        $byId = $userId ? WaConversation::where('wa_user_id', $userId)->first() : null;

        if ($byPhone && $byId && $byPhone->id !== $byId->id) {
            self::conflict($byPhone, $byId);
        }
        $conv = $byPhone ?? $byId;

        if (!$conv) {
            $conv = WaConversation::createOrFirst(
                $phone ? ['phone' => $phone] : ['wa_user_id' => $userId],
                [
                    'wa_phone' => $phone ? PhoneNormalizer::digits($from) : null,
                    'wa_user_id' => $userId,
                    'display_name' => $name,
                    'bot_enabled' => true,
                    'status' => 'open',
                ],
            );
        }

        $update = [];
        // الناقص يُملأ إن لم تحجزه محادثة أخرى
        if ($phone && !$conv->hasPhone() && !WaConversation::where('phone', $phone)->whereKeyNot($conv->id)->exists()) {
            $update['phone'] = $phone;
            $update['wa_phone'] = PhoneNormalizer::digits($from);
        }
        // معرّف جديد مع الرقم نفسه (ميتا تجدّده حين يغيّر العميل رقمه): يُحدَّث
        if ($userId && $conv->wa_user_id !== $userId && (!$conv->wa_user_id || $byPhone?->is($conv))
            && !WaConversation::where('wa_user_id', $userId)->whereKeyNot($conv->id)->exists()) {
            $update['wa_user_id'] = $userId;
        }
        if ($name !== null && $conv->wa_profile_name !== $name) {
            $update['wa_profile_name'] = $name;
        }
        if ($name !== null && $conv->display_name !== $name) {
            $update['display_name'] = $name;
        }
        if ($username !== null && $conv->wa_username !== $username) {
            $update['wa_username'] = $username;
        }

        // الربط بالعميل أو بالموظف بالرقم وحده
        $linkPhone = $update['phone'] ?? $conv->phone;
        if (filled($linkPhone)) {
            if (!$conv->client_id && ($client = PhoneNormalizer::findClient($linkPhone))) {
                $update['client_id'] = $client->id;
                $update['display_name'] = $update['display_name'] ?? $client->name;
            }
            if (!$conv->user_id && ($user = PhoneNormalizer::findUser($linkPhone))) {
                $update['user_id'] = $user->id;
            }
        }

        if ($update) {
            $conv->update($update);
        }

        return $conv->refresh();
    }

    /** الرقم في محادثة والمعرّف في أخرى: لا دمج آلي هنا (E11)، والاثنتان «تدخّل» */
    private static function conflict(WaConversation $byPhone, WaConversation $byId): void
    {
        WaLog::warning('identity.conflict', ['conv_id' => $byPhone->id, 'count' => $byId->id]);
        foreach ([$byPhone, $byId] as $c) {
            $c->forceFill(['needs_attention' => true] + (ReplyState::available() ? ['attention_reason' => 'identity_conflict'] : []))->save();
        }
        if (Cache::add("wa:identity:conflict:{$byPhone->id}:{$byId->id}", 1, now()->addDay())) {
            WhatsAppInbox::notifyAdmins('⚠️ محادثتان لعميل واحد', "المحادثتان #{$byPhone->id} و#{$byId->id} لعميل واحد حسب ميتا. الرسائل الجديدة تذهب إلى #{$byPhone->id}.");
        }
    }

    /** نص من العميل: بلا محارف تحكم ولا محارف اتجاه (U+202E يقلب العرض)، ومقصوص */
    private static function untrusted(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = trim(preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $value) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
