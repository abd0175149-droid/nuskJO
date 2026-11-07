<?php

namespace App\Services\WhatsApp\OpenWa;

use App\Jobs\ImportChannelHistory;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\WhatsApp\ContactIdentity;
use App\Services\WhatsApp\InboundMessage;
use App\Services\WhatsApp\Realtime\InboxBroadcaster;
use App\Services\WhatsApp\ReplyState;
use App\Services\WhatsApp\TextNormalizer;
use App\Services\WhatsApp\Transport\ChannelTransport;
use App\Services\WhatsApp\WaLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * استيراد سجل الرقم عند أول ربط (docs/whatsapp/openwa.md «استيراد السجل»). غير الاسترجاع بعد الانقطاع (BackfillChannel):
 * هذا يقرأ كل المحادثات الفردية إلى عمق محدد ويحفظها تاريخاً صامتاً.
 *
 * - البوابة: GET /chats بـ limit وoffset (الأحدث أولاً)، ثم GET /messages/{chatId}/history بـ limit وdeep=true
 *   (آخر N رسالة، الأقدم أولاً، بلا وسائط). لا مؤشر للرجوع أبعد، فالعمق = min(أيام، N رسالة).
 * - سلسلة وظائف (ImportChannelHistory، طابور wa-import): كل وظيفة تعمل ميزانية ثوانٍ ثم تجدول التالية، والتقدّم في
 *   wa_channels.history_import_state، فالإيقاف والاستئناف وموت العامل لا يضيّعان شيئاً (channel-watch يعيد السلسلة الميتة).
 * - التخزين: صفوف بوقت الرسالة نفسه (created_at)، بلا بوت ولا إشعار ولا عدّاد غير مقروء ولا «تدخّل» ولا قواعد ولا إلغاء
 *   متابعات ولا إيقاف تسويق ولا تنزيل وسائط (media_status=remote، تُجلب يدوياً). علامة البوت تتقدّم إلى آخر وارد مستورد،
 *   فلا يرد البوت إلا على ما يصل بعد الاستيراد.
 * - الخيط مرتب بالمعرّف: محادثة فيها رسائل قبل وصول دورها تأخذ ما بعد أحدث رسالة فيها وحده (chats_partial)، فلا تظهر
 *   رسائل قديمة بعد الحديثة. إلا محادثة كل ما فيها مستورد ولم يمسّه أحد: يُعاد بناؤها كاملة بالترتيب (rebuildable).
 * - واتساب ويب يزامن سجل الجهاز المرتبط حديثاً على دقائق، فالبدء الآلي ينتظر settle_seconds، وإعادة الاستيراد بعد ذلك
 *   تكمل ما فات. جهات @lid يُجلب رقمها من البوابة (lidPhone)، فتُربط بالعميل وبتجربة البوت قبل أن تراسل.
 */
final class HistoryImport
{
    public const RUNNING = 'running';

    public const STOPPED = 'stopped';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** إخفاقات متتالية في الخطوة نفسها قبل تخطيها (محادثة) أو إيقاف الاستيراد (قائمة المحادثات) */
    private const MAX_FAILS = 3;

    /** سلسلة بلا نبض منذ هذه الدقائق ميتة: channel-watch يعيدها */
    public const STALE_MINUTES = 5;

    public static function state(WaChannel $c): array
    {
        return (array) ($c->history_import_state ?? []);
    }

    public static function neverStarted(WaChannel $c): bool
    {
        return $c->history_import_state === null && $c->history_imported_at === null;
    }

    public static function isRunning(WaChannel $c): bool
    {
        return (self::state($c)['status'] ?? null) === self::RUNNING;
    }

    /** للواجهة: الحالة والعدادات والعمق، بلا قائمة المحادثات */
    public static function present(WaChannel $c): array
    {
        $s = self::state($c);
        $cfg = self::cfg();

        return [
            'status' => $s['status'] ?? null,
            'phase' => $s['phase'] ?? null,
            'chats_done' => (int) ($s['chats_done'] ?? 0),
            'chats_total' => isset($s['chats_total']) ? (int) $s['chats_total'] : null,
            'chats_partial' => (int) ($s['chats_partial'] ?? 0),
            'chats_skipped' => (int) ($s['chats_skipped'] ?? 0),
            'messages' => (int) ($s['messages'] ?? 0),
            'started_at' => $s['started_at'] ?? null,
            'finished_at' => $s['finished_at'] ?? null,
            'heartbeat_at' => $s['heartbeat_at'] ?? null,
            'starts_at' => $s['starts_at'] ?? null,
            'last_error' => $s['last_error'] ?? null,
            'imported_at' => $c->history_imported_at?->toIso8601String(),
            'days' => (int) ($s['days'] ?? $cfg['days']),
            'messages_per_chat' => (int) ($s['per_chat'] ?? $cfg['messages_per_chat']),
        ];
    }

    /**
     * بدء أو استئناف. الموقوف والفاشل يكملان من مؤشرهما، والمنتهي يبدأ من جديد (منع التكرار بالمعرّف يجعله آمناً).
     *
     * @return array{ok: bool, code?: string, already?: bool}
     */
    public static function start(WaChannel $c, string $by = 'manual', int $delaySeconds = 0): array
    {
        if (!$c->isLinked()) {
            return ['ok' => false, 'code' => 'CHANNEL_NOT_READY'];
        }
        $s = self::state($c);
        if (($s['status'] ?? null) === self::RUNNING && !self::stale($s)) {
            return ['ok' => true, 'already' => true];
        }
        $cfg = self::cfg();
        $resume = in_array($s['status'] ?? null, [self::STOPPED, self::FAILED, self::RUNNING], true);
        if (!$resume) {
            $s = [
                'phase' => 'list', 'offset' => 0, 'cursor' => 0, 'chats_total' => null, 'chats_done' => 0, 'chats_partial' => 0,
                'chats_skipped' => 0, 'messages' => 0, 'started_at' => now()->toIso8601String(), 'finished_at' => null,
                'days' => (int) $cfg['days'], 'per_chat' => (int) $cfg['messages_per_chat'],
                'since' => now()->subDays((int) $cfg['days'])->getTimestamp(),
            ];
            $c->forceFill(['history_queue' => []]);
        }
        // الاستئناف الآلي لسلسلة ماتت (recover) يُبقي عدّاد الأعطال، والبدء اليدوي يصفّره
        // البدء المؤجل (starts_at) ليس سلسلة ميتة: stale() لا يحسبه قبل موعده
        $s = ['status' => self::RUNNING, 'run' => (string) Str::uuid(), 'by' => $by, 'fails' => 0, 'last_error' => null,
            'crashes' => $by === 'recover' ? (int) ($s['crashes'] ?? 0) : 0, 'heartbeat_at' => now()->toIso8601String(),
            'starts_at' => $delaySeconds > 0 ? now()->addSeconds($delaySeconds)->toIso8601String() : null] + $s;
        $c->forceFill(['history_import_state' => $s])->save();
        $c->logEvent('history_import', ['action' => $resume ? 'resume' : 'start', 'by' => $by]);
        WaLog::info('openwa.import.start', ['channel_id' => $c->id, 'stage' => $resume ? 'resume' : 'start']);

        $job = ImportChannelHistory::dispatch($c->id, $s['run']);
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }

        return ['ok' => true];
    }

    public static function stop(WaChannel $c): array
    {
        $s = self::state($c);
        if (($s['status'] ?? null) !== self::RUNNING) {
            return ['ok' => true, 'already' => true];
        }
        $c->forceFill(['history_import_state' => ['status' => self::STOPPED, 'run' => null] + $s])->save();
        $c->logEvent('history_import', ['action' => 'stop', 'chats_done' => (int) ($s['chats_done'] ?? 0)]);

        return ['ok' => true];
    }

    /** سلاسل ماتت (عامل أُعيد تشغيله، أو الرقم انقطع ثم عاد): تُستأنف من مؤشرها. من whatsapp:channel-watch */
    public static function recoverStale(): int
    {
        $n = 0;
        foreach (WaChannel::query()->active()->whereNotNull('history_import_state')->get() as $c) {
            $s = self::state($c);
            if (($s['status'] ?? null) === self::RUNNING && self::stale($s) && $c->isReady()) {
                $n += (int) self::start($c, 'recover')['ok'];
            }
        }

        return $n;
    }

    /**
     * خطوات حتى نفاد ميزانية الوظيفة. @return bool هل تُجدول وظيفة تالية
     */
    public static function step(int $channelId, string $run): bool
    {
        $cfg = self::cfg();
        $t0 = microtime(true);
        $units = 0;
        while (true) {
            $c = WaChannel::find($channelId);
            if (!$c) {
                return false;
            }
            $s = self::state($c);
            if (($s['status'] ?? null) !== self::RUNNING || ($s['run'] ?? null) !== $run) {
                return false;
            }
            if ($units > 0 && microtime(true) - $t0 >= (float) $cfg['job_budget_seconds']) {
                return true;
            }
            if (!$c->isReady()) {
                // تنتظر السلسلة عودة الرقم: channel-watch يستأنفها حين يجهز (النبض القديم علامتها)
                self::save($c, ['last_error' => 'CHANNEL_NOT_READY'] + $s, heartbeat: false);

                return false;
            }
            $more = ($s['phase'] ?? 'list') === 'list' ? self::listPage($c, $s, $cfg) : self::nextChat($c, $s, $cfg);
            if (!$more) {
                return false;
            }
            $units++;
            if ((int) $cfg['sleep_ms'] > 0) {
                usleep((int) $cfg['sleep_ms'] * 1000);
            }
        }
    }

    /** خطأ غير متوقع في الوظيفة: يُسجَّل، والاستئناف من المؤشر نفسه بزر أو بـ channel-watch */
    public static function fail(int $channelId, string $run, string $error): void
    {
        $c = WaChannel::find($channelId);
        $s = $c ? self::state($c) : [];
        if (!$c || ($s['run'] ?? null) !== $run) {
            return;
        }
        // خطأ يتكرر عند المؤشر نفسه لا يُستأنف بلا نهاية: الثالث يوقف الاستيراد «فاشلاً» حتى يستأنفه المدير
        $crashes = (int) ($s['crashes'] ?? 0) + 1;
        $s = ['last_error' => mb_substr($error, 0, 200), 'crashes' => $crashes] + $s;
        if ($crashes >= self::MAX_FAILS) {
            $s = ['status' => self::FAILED, 'run' => null] + $s;
            $c->logEvent('history_import', ['action' => 'failed', 'code' => mb_substr($error, 0, 60)]);
        }
        self::save($c, $s, heartbeat: false);
    }

    /** صفحة من قائمة المحادثات: الفردية وحدها، والأحدث من بداية العمق */
    private static function listPage(WaChannel $c, array $s, array $cfg): bool
    {
        $limit = max(1, min(1000, (int) $cfg['chats_page']));
        $res = app(ChannelTransport::class)->call($c, 'GET', '/chats', null, 'import', [], ['limit' => $limit, 'offset' => (int) ($s['offset'] ?? 0)]);
        if (!$res->ok) {
            return self::failure($c, $s, 'list', (string) ($res->error?->code ?? 'GW_ERROR'));
        }
        $page = self::items($res->json());
        $since = (int) ($s['since'] ?? 0);
        $queue = (array) ($c->history_queue ?? []);
        $known = array_flip(array_column($queue, 0));
        foreach ($page as $chat) {
            $jid = is_array($chat['id'] ?? null) ? (string) ($chat['id']['_serialized'] ?? '') : (string) ($chat['id'] ?? '');
            $ts = (int) ($chat['timestamp'] ?? 0);
            if (!InboundParser::isPerson($jid) || !empty($chat['isGroup']) || isset($known[$jid]) || ($ts > 0 && $ts < $since)) {
                continue;
            }
            $known[$jid] = true;
            $queue[] = [$jid, TextNormalizer::untrustedName(is_string($chat['name'] ?? null) ? $chat['name'] : null, 120)];
        }
        $s['fails'] = 0;
        if (count($page) < $limit) {
            $s['phase'] = 'chats';
            $s['chats_total'] = count($queue);
        } else {
            $s['offset'] = (int) ($s['offset'] ?? 0) + $limit;
        }
        $c->forceFill(['history_queue' => $queue]);
        self::save($c, $s);

        return true;
    }

    private static function nextChat(WaChannel $c, array $s, array $cfg): bool
    {
        $queue = (array) ($c->history_queue ?? []);
        $cursor = (int) ($s['cursor'] ?? 0);
        if ($cursor >= count($queue)) {
            self::finish($c, $s);

            return false;
        }
        [$jid, $name] = array_pad((array) $queue[$cursor], 2, null);
        $phone = str_ends_with((string) $jid, '@lid') ? self::lidPhone($c, (string) $jid) : null;
        $res = app(ChannelTransport::class)->call($c, 'GET', '/messages/'.rawurlencode((string) $jid).'/history', null, 'import', [],
            ['limit' => (int) ($s['per_chat'] ?? $cfg['messages_per_chat']), 'deep' => 'true']);
        if (!$res->ok) {
            // محادثة لا تُقرأ بعد ثلاث محاولات تُتخطى، فلا تعلق السلسلة عليها
            if ((int) ($s['fails'] ?? 0) + 1 < self::MAX_FAILS) {
                return self::failure($c, $s, 'chat', (string) ($res->error?->code ?? 'GW_ERROR'));
            }
            $s['chats_skipped'] = (int) ($s['chats_skipped'] ?? 0) + 1;
            $s['last_error'] = (string) ($res->error?->code ?? 'GW_ERROR');
            $stored = ['stored' => 0, 'partial' => false];
        } else {
            $stored = self::storeChat($c, (string) $jid, $name, self::items($res->json()), (int) ($s['since'] ?? 0), $phone);
        }
        $s['cursor'] = $cursor + 1;
        $s['chats_done'] = (int) ($s['chats_done'] ?? 0) + 1;
        $s['messages'] = (int) ($s['messages'] ?? 0) + $stored['stored'];
        $s['chats_partial'] = (int) ($s['chats_partial'] ?? 0) + ($stored['partial'] ? 1 : 0);
        $s['fails'] = 0;
        self::save($c, $s);

        return true;
    }

    /**
     * سجل محادثة واحدة في معاملة واحدة (لا نداء للبوابة داخلها). عام للاختبار وللاستيراد اليدوي.
     *
     * @param  list<array>  $messages  IncomingMessage كما تعيده البوابة
     * @param  string|null  $phone  رقم جهة @lid من البوابة، إن لم تحمله الرسائل
     * @return array{stored: int, partial: bool, rebuilt: bool, conversation_id: ?int}
     */
    public static function storeChat(WaChannel $c, string $jid, ?string $chatName, array $messages, int $since = 0, ?string $phone = null): array
    {
        $parsed = [];
        foreach ($messages as $i => $m) {
            if (!is_array($m) || (int) ($m['timestamp'] ?? 0) < $since) {
                continue;
            }
            $m['chatId'] = $jid;
            $in = InboundParser::parse($m, $c);
            if ($in === null || !$in->wamid) {
                continue;
            }
            $parsed[] = [$in, $m, $i];
        }
        if ($parsed === []) {
            return ['stored' => 0, 'partial' => false, 'rebuilt' => false, 'conversation_id' => null];
        }
        // الأقدم أولاً (البوابة تعيده كذلك)، والترتيب الأصلي يحسم التساوي
        usort($parsed, fn ($a, $b) => [$a[0]->timestamp, $a[2]] <=> [$b[0]->timestamp, $b[2]]);

        $identity = collect($parsed)->first(fn ($p) => empty($p[1]['fromMe'])) ?? $parsed[0];
        /** @var InboundMessage $who */
        $who = $identity[0];

        [$conv, $stored, $partial, $rebuilt] = DB::transaction(function () use ($c, $parsed, $who, $chatName, $phone) {
            $conv = ContactIdentity::resolveIdentity($who->fromPhone ?? $phone ?? (InboundParser::digits((string) $who->jid) ?: null), null,
                $who->profileName ?? $chatName, null, null, channelId: (int) $c->id, jid: $who->jid, lid: $who->lid);

            $existing = WaMessage::where('conversation_id', $conv->id);
            $hadMessages = (clone $existing)->exists();
            $created = !$hadMessages;
            $newest = $hadMessages ? (clone $existing)->max('created_at') : null;
            $newestTs = $newest ? Carbon::parse($newest)->getTimestamp() : 0;
            // إعادة استيراد وصلت أقدم مما حُفظ (المزامنة الأولى لم تكتمل): محادثة مستوردة كلها تُبنى من جديد بالترتيب
            $removed = 0;
            if ($hadMessages && self::at($parsed[0][0])->getTimestamp() < $newestTs && self::rebuildable((int) $conv->id)) {
                $removed = (int) WaMessage::where('conversation_id', $conv->id)->delete();
                $hadMessages = false;
                $newestTs = 0;
            }
            $ids = array_map(fn ($p) => (string) $p[0]->wamid, $parsed);
            $dupes = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $dupes += array_flip(WaMessage::whereIn('wamid', $chunk)
                    ->where(fn ($q) => $q->where('channel_id', $c->id)->orWhereNull('channel_id'))->pluck('wamid')->all());
            }

            $stored = 0;
            $partial = false;
            $lastIn = $lastOut = null;
            $lastInId = 0;
            $last = null;
            foreach ($parsed as [$in, $m]) {
                if (isset($dupes[(string) $in->wamid])) {
                    continue;
                }
                $at = self::at($in);
                // الخيط مرتب بالمعرّف: ما سبق أحدث رسالة موجودة لا يُدرج بعدها
                if ($hadMessages && $at->getTimestamp() <= $newestTs) {
                    $partial = true;

                    continue;
                }
                $row = self::row($conv, $c, $in, $m, $at);
                $dupes[(string) $in->wamid] = true;
                $stored++;
                $last = [$at, $in->body];
                if ($row->direction === 'in') {
                    $lastIn = $at;
                    $lastInId = $row->id;
                } else {
                    $lastOut = $at;
                }
            }

            if ($stored > 0) {
                $conv->refresh();
                $update = [];
                if ($conv->last_message_at === null || $conv->last_message_at->lt($last[0])) {
                    $update += ['last_message_at' => $last[0], 'last_message_preview' => mb_substr(trim((string) $last[1]), 0, 160)];
                }
                if ($lastIn && ($conv->last_inbound_at === null || $conv->last_inbound_at->lt($lastIn))) {
                    $update['last_inbound_at'] = $lastIn;
                }
                if ($lastOut && ($conv->last_outbound_at === null || $conv->last_outbound_at->lt($lastOut))) {
                    $update['last_outbound_at'] = $lastOut;
                }
                // محادثة أنشأها الاستيراد: مغلقة وبلا غير مقروء، وأول وارد حي يعيد فتحها (WhatsAppInbox::storeInbound)
                if ($created && self::cfg()['close_new']) {
                    $update += ['status' => 'closed', 'closed_at' => $last[0], 'unread_count' => 0];
                }
                if ($update !== []) {
                    $conv->forceFill($update)->save();
                }
                // البوت لا يرد على السجل: العلامة إلى آخر وارد مستورد
                if ($lastInId > 0) {
                    ReplyState::advance($conv, $lastInId);
                }
            }

            // العدّاد للجديد وحده: المعاد بناؤه حُسب في الجولة الأولى
            return [$conv, max(0, $stored - $removed), $partial, $removed > 0];
        });

        if ($stored > 0 || $rebuilt) {
            rescue(fn () => app(InboxBroadcaster::class)->touch((int) $conv->id, 'message'), null, false);
        }

        return ['stored' => $stored, 'partial' => $partial, 'rebuilt' => $rebuilt, 'conversation_id' => (int) $conv->id];
    }

    /**
     * محادثة كل رسائلها من الاستيراد ولم يمسّها أحد: لا وارد ولا صادر حي، ولا وسيط جُلب إلى الخادم، ولا تثبيت ولا طلب
     * سعر من رسائلها. حذف صفوفها وإعادة إدراجها لا يكسر مرجعاً (فهرس البحث يتبع بالـ triggers).
     */
    private static function rebuildable(int $convId): bool
    {
        $msgs = WaMessage::where('conversation_id', $convId);
        $touched = (clone $msgs)->where(fn ($q) => $q->whereNull('payload')
            ->orWhereRaw('coalesce(json_extract(payload, \'$._import\'), 0) != 1')
            ->orWhereNotNull('media_path'))->exists();
        if ($touched) {
            return false;
        }
        $ids = (clone $msgs)->select('id');
        if (Schema::hasTable('wa_thread_pins') && DB::table('wa_thread_pins')->whereIn('message_id', $ids)->exists()) {
            return false;
        }

        return !(Schema::hasColumn('quote_requests', 'source_msg_id') && DB::table('quote_requests')->whereIn('source_msg_id', $ids)->exists());
    }

    /** رقم جهة @lid من البوابة (GET /contacts/{id}/phone)، وإلا null فتبقى بمعرّف الخصوصية حتى أول وارد حي */
    private static function lidPhone(WaChannel $c, string $jid): ?string
    {
        $res = app(ChannelTransport::class)->call($c, 'GET', '/contacts/'.rawurlencode($jid).'/phone', null, 'import');
        $json = $res->ok ? (array) $res->json() : [];
        $phone = preg_replace('/\D/', '', is_scalar($json['phone'] ?? null) ? (string) $json['phone'] : '');

        return strlen($phone) >= 7 && strlen($phone) <= 15 ? $phone : null;
    }

    private static function row(WaConversation $conv, WaChannel $c, InboundMessage $in, array $m, Carbon $at): WaMessage
    {
        $out = !empty($m['fromMe']);
        $row = new WaMessage;
        $row->forceFill(array_filter([
            'conversation_id' => $conv->id,
            'channel_id' => $c->id,
            'wamid' => $in->wamid,
            'direction' => $out ? 'out' : 'in',
            // ما كتبه الرقم قبل ربطه بنُسك كتبه شخص من الهاتف: «من هاتف الوكالة» كصدى الهاتف الحي
            'source' => $out ? 'staff_phone' : 'customer',
            'msg_type' => $in->type,
            'body' => $in->body,
            'payload' => EventRouter::storable($m) + ['_import' => true],
            'status' => $out ? WaMessage::STATUS_SENT : null,
            'sent_at' => $out ? $at : null,
            'reply_to_wamid' => $in->replyToWamid,
            'media_id' => $in->media['id'] ?? null,
            'media_mime' => $in->media['mime'] ?? null,
            'media_filename' => $in->media['filename'] ?? null,
            // لا تنزيل للسجل: الملف على الهاتف، ويُطلب يدوياً من الخيط
            'media_status' => ($in->media['id'] ?? null) && self::hasMediaStatus() ? 'remote' : null,
            'meta_at' => !$out && self::hasMetaAt() ? $at : null,
            'created_at' => $at,
            'updated_at' => $at,
        ], fn ($v) => $v !== null));
        $row->timestamps = false;
        $row->save();

        return $row;
    }

    private static function at(InboundMessage $in): Carbon
    {
        return $in->timestamp > 0 ? min(Carbon::createFromTimestamp($in->timestamp, config('app.timezone')), now()) : now();
    }

    private static function finish(WaChannel $c, array $s): void
    {
        $s['status'] = self::DONE;
        $s['run'] = null;
        $s['finished_at'] = now()->toIso8601String();
        $c->forceFill(['history_imported_at' => now(), 'history_queue' => null]);
        self::save($c, $s);
        $c->logEvent('history_import', ['action' => 'done', 'chats' => (int) ($s['chats_done'] ?? 0), 'messages' => (int) ($s['messages'] ?? 0),
            'partial' => (int) ($s['chats_partial'] ?? 0), 'skipped' => (int) ($s['chats_skipped'] ?? 0)]);
        WaLog::info('openwa.import.done', ['channel_id' => $c->id, 'count' => (int) ($s['messages'] ?? 0)]);
    }

    /** إخفاق خطوة: تُعاد في الوظيفة التالية، وبعد ثلاثة متتالية في قائمة المحادثات يتوقف الاستيراد «فاشلاً» */
    private static function failure(WaChannel $c, array $s, string $where, string $code): bool
    {
        $s['fails'] = (int) ($s['fails'] ?? 0) + 1;
        $s['last_error'] = $code;
        WaLog::warning('openwa.import.step_failed', ['channel_id' => $c->id, 'stage' => $where, 'gw_code' => $code]);
        if ($where === 'list' && $s['fails'] >= self::MAX_FAILS) {
            $s['status'] = self::FAILED;
            $s['run'] = null;
            self::save($c, $s);
            $c->logEvent('history_import', ['action' => 'failed', 'code' => $code]);

            return false;
        }
        self::save($c, $s);

        return true;
    }

    private static function save(WaChannel $c, array $s, bool $heartbeat = true): void
    {
        if ($heartbeat) {
            $s['heartbeat_at'] = now()->toIso8601String();
        }
        $c->forceFill(['history_import_state' => $s])->save();
    }

    private static function stale(array $s): bool
    {
        // آخر نبض أو موعد البدء المؤجل، أيهما أحدث
        $times = array_filter([$s['heartbeat_at'] ?? null, $s['starts_at'] ?? null]);
        if ($times === []) {
            return true;
        }

        return max(array_map(fn ($t) => Carbon::parse($t)->getTimestamp(), $times)) < now()->subMinutes(self::STALE_MINUTES)->getTimestamp();
    }

    /** مصفوفة الرد: قائمة مباشرة، أو داخل items/chats/messages/data */
    private static function items(mixed $json): array
    {
        if (!is_array($json)) {
            return [];
        }
        foreach (['items', 'chats', 'messages', 'data'] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                return array_values($json[$k]);
            }
        }

        return array_is_list($json) ? $json : [];
    }

    private static function cfg(): array
    {
        return (array) config('whatsapp.openwa.import', []) + [
            'auto' => true, 'days' => 180, 'messages_per_chat' => 500, 'chats_page' => 500, 'sleep_ms' => 300,
            'job_budget_seconds' => 25, 'close_new' => true, 'settle_seconds' => 600,
        ];
    }

    private static ?bool $hasMetaAt = null;

    private static ?bool $hasMediaStatus = null;

    private static function hasMetaAt(): bool
    {
        return self::$hasMetaAt ??= Schema::hasColumn('wa_messages', 'meta_at');
    }

    private static function hasMediaStatus(): bool
    {
        return self::$hasMediaStatus ??= Schema::hasColumn('wa_messages', 'media_status');
    }
}
