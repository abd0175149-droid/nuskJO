<?php

namespace App\Services\WhatsApp;

use App\Events\WhatsApp\WaMessageStored;
use App\Models\WaBotSetting;
use App\Models\WaCall;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\NotificationService;
use App\Services\Ops\OpsHealth;
use App\Support\FeatureFlags;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حقل الويبهوك calls (E39، العلم calls_webhook؛ بدونه سلوك E20: نوع الحدث وعدده فقط). سجل وتنبيه ومتابعة، لا رد:
 * الرد يحتاج WebRTC بعرض SDP أو ربط SIP، وكلاهما خارج هذه الملحمة.
 *
 * - connect من العميل: صف wa_calls (ringing)، ويفتح نافذة الـ24 ساعة أو يجددها (موثّق عند ميتا، أُجيب أم لا)
 *   بتحديث رتيب لـ last_inbound_at. لا إرسال ولا pre_accept ولا accept، ولا تشغيل للبوت.
 * - terminate: الحالة والمدة، ورسالة نظام msg_type=call في الخيط، والفائتة (لا COMPLETED أو بلا مدة) ترفع «تدخّل»
 *   بالسبب missed_call وتُشعر المختص أو المدراء، والمتابعة النصية خلف calls_followup (CallFollowup).
 * - statuses لمكالمة يبدؤها النشاط: يُحدَّث الصف إن وُجد فقط (لا مكالمات صادرة في هذه الملحمة).
 * لا يُسجَّل رقم المتصل ولا SDP: رقم المحادثة وبصمة مقتطعة لمعرّف المكالمة. والحدث لرقم آخر أو الناقص يُعدّ ويُترك.
 */
final class CallEventHandler
{
    /** طريقة الرد المعتمدة (calls_answer_mode): none يعني أن كل مكالمة ستفوت */
    public const ANSWER_MODES = ['none', 'webrtc', 'sip'];

    public const REJECT_REASONS = ['foreign', 'incomplete'];

    private static ?bool $available = null;

    /** الجدول موجود (بين النشر والهجرة 2026_10_14_000039 لا يوجد، فيبقى سلوك E20) */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('wa_calls');
    }

    public static function enabled(): bool
    {
        return self::available() && FeatureFlags::on('calls_webhook');
    }

    /** value.calls[] وvalue.statuses[] لرقمنا، كل عنصر معزول. لا جدولة لرد البوت أبداً (لا touch) */
    public static function handle(array $value, string $ownId, WebhookResult $result): void
    {
        $pnid = (string) data_get($value, 'metadata.phone_number_id', '');
        if ($ownId === '' || $pnid !== $ownId) {
            self::rejected('foreign', count((array) data_get($value, 'calls', [])) + count((array) data_get($value, 'statuses', [])));

            return;
        }
        $contacts = (array) data_get($value, 'contacts', []);

        foreach ((array) data_get($value, 'calls', []) as $i => $call) {
            try {
                self::event((array) $call, $contacts, $pnid);
            } catch (\Throwable $e) {
                $ref = self::ref($call) ?? "call.{$i}";
                $result->fail('call', $ref, $e);
                WaLog::error('webhook.item_failed', ['field' => 'calls', 'item' => $ref, 'exception' => $e]);
            }
        }
        foreach ((array) data_get($value, 'statuses', []) as $i => $status) {
            try {
                self::status((array) $status);
            } catch (\Throwable $e) {
                $ref = self::ref($status) ?? "call_status.{$i}";
                $result->fail('call_status', $ref, $e);
                WaLog::error('webhook.item_failed', ['field' => 'calls', 'item' => $ref, 'exception' => $e]);
            }
        }
    }

    private static function event(array $call, array $contacts, string $pnid): void
    {
        $id = self::callId($call);
        if ($id === null) {
            self::rejected('incomplete');

            return;
        }

        match (strtolower((string) data_get($call, 'event'))) {
            'connect' => self::connect($id, $call, $contacts, $pnid),
            'terminate' => self::terminate($id, $call, $contacts, $pnid),
            default => WaLog::info('calls.event_ignored', ['event' => WebhookFields::name(data_get($call, 'event')), 'call' => self::hash($id)]),
        };
    }

    // ==================== الاتصال ====================

    private static function connect(string $id, array $call, array $contacts, string $pnid): void
    {
        $who = self::caller($call);
        if ($who === null) {
            self::rejected('incomplete');

            return;
        }
        $inbound = self::direction($call) === 'USER_INITIATED';
        $at = self::time(data_get($call, 'timestamp')) ?? now();

        try {
            [$conv, $row] = DB::transaction(function () use ($id, $call, $who, $contacts, $pnid, $at, $inbound) {
                $conv = self::conversation($who, $contacts, $pnid);
                $row = WaCall::firstOrCreate(['wa_call_id' => $id], [
                    'conversation_id' => $conv->id,
                    'direction' => self::direction($call),
                    'status' => 'ringing',
                    'connect_at' => $at,
                ]);
                // اتصال العميل يفتح النافذة أو يجددها: رتيب، فحدث أقدم أعادته ميتا لا يرجعها
                if ($inbound) {
                    self::openWindow($conv->id, $at);
                }

                return [$conv, $row];
            });
        } catch (UniqueConstraintViolationException) {
            // نسختان من الحدث نفسه معاً: الأخرى سجّلته
            return;
        }
        WaLog::info('calls.connect', ['conv_id' => $conv->id, 'call' => self::hash($id), 'event' => $row->wasRecentlyCreated ? 'new' : 'repeat']);

        // C045: لا طريقة رد معتمدة والرفض الآلي مفعّل: يُنهى الرنين فوراً بدل أن يفوت (المتابعة النصية بعد الانتهاء)
        if ($inbound && $row->wasRecentlyCreated && self::autoReject()) {
            $res = WhatsAppChannel::rejectCall($id);
            if ($res['ok']) {
                WaCall::whereKey($row->id)->update(['status' => 'rejected', 'rejected_by_us' => true]);
            }
            WaLog::info('calls.auto_reject', ['conv_id' => $conv->id, 'call' => self::hash($id), 'code' => $res['code'] ?? null]);
        }
    }

    // ==================== الانتهاء ====================

    private static function terminate(string $id, array $call, array $contacts, string $pnid): void
    {
        $status = strtoupper((string) data_get($call, 'status'));
        $duration = is_numeric(data_get($call, 'duration')) ? max(0, (int) data_get($call, 'duration')) : null;
        $missed = $status !== 'COMPLETED' || !$duration;
        $inbound = self::direction($call) === 'USER_INITIATED';
        $at = self::time(data_get($call, 'timestamp')) ?? now();

        try {
            $done = DB::transaction(function () use ($id, $call, $contacts, $pnid, $at, $inbound, $missed, $duration) {
                $row = WaCall::where('wa_call_id', $id)->first();
                if ($row === null) {
                    // الاتصال لم يصلنا: الانتهاء وحده يكفي للسجل (ويحمل الرقم أيضاً)
                    $who = self::caller($call);
                    if ($who === null) {
                        return 'incomplete';
                    }
                    $conv = self::conversation($who, $contacts, $pnid);
                    $row = WaCall::create(['wa_call_id' => $id, 'conversation_id' => $conv->id, 'direction' => self::direction($call),
                        'status' => 'ringing', 'connect_at' => $at]);
                    if ($inbound) {
                        self::openWindow($conv->id, $at);
                    }
                } else {
                    $conv = self::live((int) $row->conversation_id);
                }
                if ($conv === null) {
                    return 'incomplete';
                }

                // ملء thread_message_id يعني أن الانتهاء عولج: إعادة ميتا لا تضيف رسالة ولا إشعاراً
                $claimed = WaCall::whereKey($row->id)->whereNull('thread_message_id')->update([
                    'status' => $row->rejected_by_us ? 'rejected' : ($missed ? 'missed' : 'completed'),
                    'missed' => $inbound && $missed,
                    'start_time' => self::time(data_get($call, 'start_time')),
                    'end_time' => self::time(data_get($call, 'end_time')),
                    'duration_seconds' => $duration,
                    'updated_at' => now(),
                ]);
                if ($claimed === 0) {
                    return 'duplicate';
                }
                if (!$inbound) {
                    // مكالمة يبدؤها النشاط ليست في هذه الملحمة: السجل وحده
                    WaCall::whereKey($row->id)->update(['thread_message_id' => 0]);

                    return 'outbound';
                }

                $body = $missed
                    ? '📞 مكالمة واردة فائتة '.self::local($row->connect_at ?? $at)->format('H:i')
                    : '📞 مكالمة واردة، مدتها '.intdiv((int) $duration, 60).':'.str_pad((string) ((int) $duration % 60), 2, '0', STR_PAD_LEFT);
                $message = WaMessage::create([
                    'conversation_id' => $conv->id,
                    'direction' => 'in',
                    'source' => 'system',
                    'msg_type' => 'call',
                    'body' => $body,
                    'payload' => ['_call' => ['missed' => $missed, 'duration' => $duration]],
                ]);
                WaCall::whereKey($row->id)->update(['thread_message_id' => $message->id]);

                // المعاينة لا تتراجع
                WaConversation::whereKey($conv->id)
                    ->where(fn ($q) => $q->whereNull('last_message_at')->orWhere('last_message_at', '<=', now()))
                    ->update(['last_message_at' => now(), 'last_message_preview' => mb_substr($body, 0, 160)]);
                if ($missed) {
                    // forceFill ثم save: البث اللحظي للقائمة والرأس (E28) من حدث حفظ النموذج
                    $conv->forceFill(['needs_attention' => true] + (ReplyState::available() ? ['attention_reason' => 'missed_call'] : []))->save();
                }

                return [$conv, $row->fresh(), $message];
            });
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($done === 'incomplete') {
            self::rejected('incomplete');

            return;
        }
        if (!is_array($done)) {
            WaLog::info('calls.terminate', ['call' => self::hash($id), 'event' => $done]);

            return;
        }

        [$conv, $row, $message] = $done;
        EventBus::emit(new WaMessageStored($message->id, $conv->id, 'call'));
        WaLog::info('calls.terminate', ['conv_id' => $conv->id, 'call' => self::hash($id), 'event' => $missed ? 'missed' : 'completed']);
        if ($missed) {
            self::notifyMissed($conv->fresh() ?? $conv);
            CallFollowup::afterMissed($conv, $row);
        }
    }

    /** حالة مكالمة يبدؤها النشاط (RINGING وACCEPTED وREJECTED): الصف الموجود وحده */
    private static function status(array $status): void
    {
        $id = self::callId($status);
        $to = ['RINGING' => 'ringing', 'ACCEPTED' => 'accepted', 'REJECTED' => 'rejected'][strtoupper((string) data_get($status, 'status'))] ?? null;
        if ($id === null || $to === null) {
            return;
        }
        WaCall::where('wa_call_id', $id)->whereNull('thread_message_id')->update(['status' => $to, 'updated_at' => now()]);
    }

    /**
     * إشعار المختص (توكيل قائم أو مختص الموضوع أو موظف العميل)، وإلا المدراء. قراءة فقط: لا توكيل جديد.
     * مرة لكل محادثة في whatsapp.calls.notify_minutes. الجرس بالاسم بلا رقم، ودفع الجهاز عام (E15.10).
     */
    private static function notifyMissed(WaConversation $conv): void
    {
        if (!Cache::add("wa:call:notify:{$conv->id}", 1, now()->addMinutes((int) config('whatsapp.calls.notify_minutes', 10)))) {
            return;
        }
        $until = $conv->last_inbound_at?->copy()->addDay();
        $title = '📞 مكالمة فائتة';
        $body = $conv->noticeLabel().' اتصل ولم يُرد عليه'.($until && $until->isFuture() ? ' — النافذة مفتوحة للمراسلة حتى '.self::local($until)->format('H:i') : '');
        $url = '/whatsapp/inbox?conv='.$conv->id;

        $assignee = TopicRouter::assigneeFor($conv);
        if ($assignee) {
            try {
                NotificationService::send((int) $assignee, $title, $body, ['type' => 'whatsapp', 'icon' => '📞', 'action_url' => $url]);
            } catch (\Throwable $e) {
                WaLog::error('notify.failed', ['conv_id' => $conv->id, 'exception' => $e]);
            }

            return;
        }
        WhatsAppInbox::notifyAdmins($title, $body, $url);
    }

    // ==================== مساعدات ====================

    /** @return array{phone: ?string, user_id: ?string}|null المتصل: الرقم أو معرّف BSUID (لا معرّف الحساب الأب) */
    private static function caller(array $call): ?array
    {
        $from = data_get($call, 'from');
        $uid = data_get($call, 'from_user_id');
        $phone = is_string($from) && PhoneNormalizer::isPhoneLike($from) ? $from : null;
        $uid = is_string($uid) && preg_match(InboundMessage::BSUID, $uid) && !str_contains($uid, '.ENT.') ? $uid : null;

        return $phone === null && $uid === null ? null : ['phone' => $phone, 'user_id' => $uid];
    }

    /** محادثة المتصل عبر هوية E11، تُنشأ إن لم توجد كما تفعل الرسالة */
    private static function conversation(array $who, array $contacts, string $pnid): WaConversation
    {
        $contact = ContactIdentity::contactFor($contacts, $who['phone'], $who['user_id']);

        return ContactIdentity::resolveIdentity($who['phone'], $who['user_id'],
            TextNormalizer::untrustedName(data_get($contact, 'profile.name'), 120),
            TextNormalizer::untrustedName(data_get($contact, 'profile.username'), 100), $pnid);
    }

    /** المحادثة الحية للصف: المدموجة تُتبع إلى من دُمجت فيها */
    private static function live(int $convId): ?WaConversation
    {
        $conv = WaConversation::find($convId);
        if ($conv && $conv->status === 'merged' && $conv->merged_into_id) {
            return WaConversation::find($conv->merged_into_id) ?? $conv;
        }

        return $conv;
    }

    private static function openWindow(int $convId, Carbon $at): void
    {
        WaConversation::whereKey($convId)
            ->where(fn ($q) => $q->whereNull('last_inbound_at')->orWhere('last_inbound_at', '<', $at))
            ->update(['last_inbound_at' => $at]);
    }

    private static function autoReject(): bool
    {
        $s = WaBotSetting::current();

        return (bool) $s->calls_auto_reject && ($s->calls_answer_mode ?: 'none') === 'none';
    }

    private static function direction(array $call): string
    {
        return strtoupper((string) data_get($call, 'direction')) === 'BUSINESS_INITIATED' ? 'BUSINESS_INITIATED' : 'USER_INITIATED';
    }

    private static function callId(mixed $item): ?string
    {
        $id = data_get($item, 'id');

        return is_string($id) && $id !== '' && strlen($id) <= 128 ? $id : null;
    }

    /** وقت ميتا (ثوانٍ أو نص)، لا يتجاوز «الآن» */
    private static function time(mixed $ts): ?Carbon
    {
        if (is_numeric($ts) && (int) $ts > 0) {
            return min(Carbon::createFromTimestamp((int) $ts, config('app.timezone')), now());
        }
        if (is_string($ts) && $ts !== '') {
            return rescue(fn () => min(Carbon::parse($ts)->setTimezone(config('app.timezone')), now()), null, false);
        }

        return null;
    }

    private static function local(Carbon $at): Carbon
    {
        return $at->copy()->setTimezone((string) config('whatsapp.calls.timezone', 'Asia/Amman'));
    }

    /** بصمة مقتطعة لمعرّف المكالمة للسجل (كما في E20) */
    public static function hash(string $id): string
    {
        return substr(sha1($id), 0, 10);
    }

    private static function ref(mixed $item): ?string
    {
        $id = self::callId($item);

        return $id === null ? null : 'call:'.self::hash($id);
    }

    /** حدث لرقم آخر أو ناقص (بلا معرّف أو بلا متصل): عدّاد يومي يُعرض في بطاقة المكالمات، بلا محتوى */
    private static function rejected(string $reason, int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        $store = OpsHealth::store();
        $key = "wa:calls:rejected:{$reason}:".now()->format('Ymd');
        $store->add($key, 0, now()->addDays(8));
        $store->increment($key, $n);
        WaLog::warning('calls.rejected', ['reason' => $reason, 'count' => $n]);
    }

    /** @return array<string, int> المرفوض اليوم وخلال 7 أيام */
    public static function rejectedCounts(): array
    {
        $store = OpsHealth::store();
        $out = ['today' => 0, 'week' => 0];
        for ($d = 0; $d < 7; $d++) {
            $day = now()->subDays($d)->format('Ymd');
            foreach (self::REJECT_REASONS as $reason) {
                $n = (int) $store->get("wa:calls:rejected:{$reason}:{$day}", 0);
                $out['week'] += $n;
                $out['today'] += $d === 0 ? $n : 0;
            }
        }

        return $out;
    }
}
