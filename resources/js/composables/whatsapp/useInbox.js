// حالة صندوق المحادثات ونداءاته (E16): القائمة بصفحات، والخيط بمؤشراته، والإجراءات، والتنقل على الجوال.
// منقول من Inbox.vue القديم بسلوكه (tests/js/INBOX_BEHAVIOR.md): السياج 403/404، وseq يُهمل الردود المتأخرة،
// والاستطلاع بـ after/since، و«رسائل جديدة ↓»، والمسودة لكل محادثة، وتحذير السياسة 409 (E15).
// E30: القفز إلى رسالة (around) ووضع «منفصل عن الأحدث» لا يسحبه الاستطلاع، وفلاتر الخيط، وحارس ترتيب لطلبات القائمة.
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import { useDrafts } from '@/composables/useDrafts';
import { createSendKeys } from '@/composables/whatsapp/useSendKey.js';
import { appendPage, cursorOf, mergeFirstPage } from './useConversationList.js';
import { applyUpdates, lastId, mergeNewer, mergeOlder, needsReload } from './useThread.js';

const OUT_OF_SCOPE = 'هذه المحادثة غير متاحة لك: ربما نُقلت لموظف آخر أو أُزيل وسم اختصاصك. اطلب من المدير توكيلها لك إن كنت تحتاجها';
const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const threadUrl = (id, action) => `/api/whatsapp/conversations/${id}/${action}`;
const gone = (e) => [403, 404].includes(e?.response?.status);
const hhmmNow = () => new Date().toLocaleTimeString('ar-JO', { hour: '2-digit', minute: '2-digit' });

export function errorText(e, fallback) {
    const d = e?.response?.data || {};
    const text = d.error || Object.values(d.errors || {})[0]?.[0] || d.message;
    // رسائل Laravel الافتراضية إنجليزية؛ رسائلنا عربية
    if (e?.response?.status === 403 && !/[؀-ۿ]/.test(text || '')) return 'ليست لديك صلاحية لهذا الإجراء';
    // سبب ميتا ثم الإجراء المقترح (E18)
    return text ? (d.action ? `${text} — ${d.action}` : text) : fallback;
}

/** @param thread دالة تعيد مكوّن ThreadView (nearBottom وtoBottom وkeepOnPrepend) */
export function useInbox(props, thread) {
    const s = reactive({
        list: [...(props.conversations || [])],
        listHasMore: !!props.listMeta?.has_more,
        loadingMore: false,
        llm: { ...(props.llm || {}) },
        staleSince: '',
        filter: props.filters?.filter || 'all',
        search: props.filters?.search || '',
        topic: props.filters?.topic || '',
        // تصنيف داخلي (E29)
        label: props.filters?.label ? String(props.filters.label) : '',
        activeId: null,
        thread: null,
        messages: [],
        notes: [],
        hasMore: false,
        loadingOlder: false,
        newBelow: 0,
        notice: '',
        sending: false,
        sendError: '',
        requesting: false,
        // الجوال: إما القائمة وإما المحادثة (تحت lg)
        view: 'list',
        // بطاقات أعلى الصندوق (E17): أعداد فقط
        summary: props.summary || null,
        // E30: نافذة حول رسالة قديمة لم تلحق بالأحدث، وما وصل بعدها، وفلاتر الخيط
        detached: false,
        anchorId: null,
        loadingNewer: false,
        pendingNew: 0,
        pendingMore: false,
        filtered: false,
        threadFilters: {},
    });
    let failures = 0;
    let syncedAt = null;
    let seq = 0;
    let inflight = null;
    // طلبات القائمة: رد أقدم لا يغلب رداً أحدث (الاستطلاع والبحث بالاسم يتسابقان)، وصفحة «المزيد» لفلتر قديم تُهمل
    let listSeq = 0;
    let listGen = 0;
    // أحدث رسالة معروفة والخيط منفصل: الاستطلاع يعدّ ما بعدها ولا يلحقه بالنافذة
    let latestKnown = null;

    const drafts = useDrafts();
    // مفتاح منع التكرار لكل مسودة (E19): ثابت لإعادة المحاولة بالنص نفسه
    const sendKeys = createSendKeys();
    const draft = computed({ get: () => drafts.get(s.activeId), set: (t) => drafts.set(s.activeId, t) });
    const mode = computed(() => s.thread?.identity_mode || props.identityMode);
    const cannotReachPhoneless = computed(() => !!s.thread?.phoneless && mode.value === 'store');
    const canRequestPhone = computed(() => props.canReply && !!s.thread?.phoneless && !!s.thread?.window_open && mode.value !== 'store');

    const params = () => ({ filter: s.filter, search: s.search || undefined, topic: s.topic || undefined, label: s.label || undefined });

    /** الصفحة الأولى؛ تُدمج مع ما حُمّل بعدها. يعيد false عند الفشل (بعد فشلين متتاليين شريط «تعذّر التحديث») */
    async function load({ reset = false } = {}) {
        const mine = ++listSeq;
        if (reset) listGen++;
        try {
            const { data } = await axios.get('/api/whatsapp/conversations', { ...api, params: params() });
            // طلب أحدث بدأ بعد هذا: ردّه هو المعتمد
            if (mine !== listSeq) return true;
            const page = data.conversations || [];
            if (reset) {
                s.list = page;
                s.listHasMore = !!data.has_more;
            } else {
                const merged = mergeFirstPage(s.list, page, !!data.has_more);
                if (merged.length <= page.length) s.listHasMore = !!data.has_more;
                s.list = merged;
            }
            if (data.llm) s.llm = data.llm;
            failures = 0;
            s.staleSince = '';
            return true;
        } catch {
            failures++;
            if (failures === 2) s.staleSince = hhmmNow();
            return false;
        }
    }

    async function loadMore() {
        if (s.loadingMore || !s.listHasMore) return;
        s.loadingMore = true;
        const gen = listGen;
        try {
            const { data } = await axios.get('/api/whatsapp/conversations', { ...api, params: { ...params(), cursor: cursorOf(s.list) } });
            if (gen !== listGen) return;
            s.list = appendPage(s.list, data.conversations || []);
            s.listHasMore = !!data.has_more;
        } catch {
            s.staleSince ||= hhmmNow();
        } finally {
            s.loadingMore = false;
        }
    }

    function resetThread() {
        seq++;
        Object.assign(s, { activeId: null, thread: null, messages: [], notes: [], hasMore: false, loadingOlder: false, newBelow: 0, threadFilters: {} });
        leaveDetached();
        syncedAt = null;
    }

    /** فلاتر الخيط (E30) معاملاتٍ للخادم، بلا الفارغ */
    const filterParams = () => Object.fromEntries(Object.entries(s.threadFilters || {}).filter(([, v]) => v));

    function leaveDetached() {
        Object.assign(s, { detached: false, anchorId: null, loadingNewer: false, pendingNew: 0, pendingMore: false });
        latestKnown = null;
    }

    /**
     * المحادثة المفتوحة في ?conv=. الإدخال المدفوع من القائمة (الجوال) بلا page من Inertia، فلا يعيد Inertia كتابته
     * (حفظ التمرير يتخطّى ما لا page فيه)، ويعلَّم inboxPushed ليعرف زر الرجوع أن خلفه القائمة.
     */
    function setUrl(id, push) {
        const url = new URL(window.location.href);
        if (id) url.searchParams.set('conv', id);
        else url.searchParams.delete('conv');
        const target = url.pathname + url.search + url.hash;
        if (push) window.history.pushState({ inboxPushed: true }, '', target);
        else window.history.replaceState(withPageUrl(window.history.state, target), '', target);
    }

    /** صفحة Inertia المحفوظة في الإدخال تحمل رابطها، ويعيده Inertia عند الرجوع بإعادة تحميل: يُحدَّث معه */
    function withPageUrl(state, url) {
        const page = state?.page;
        return page && typeof page === 'object' && 'url' in page ? { ...state, page: { ...page, url } } : state;
    }

    /** المحادثة خرجت من نطاق الموظف: تُغلق ويُشرح السبب */
    function closeOutOfScope() {
        resetThread();
        s.notice = OUT_OF_SCOPE;
        s.view = 'list';
        setUrl(null, false);
        load();
    }

    async function markRead(id) {
        if (!props.canReply || !id || !(s.thread?.unread > 0)) return;
        try {
            await axios.post(threadUrl(id, 'read'), {}, api);
            if (s.activeId === id && s.thread) s.thread.unread = 0;
            const row = s.list.find((c) => c.id === id);
            if (row) row.unread = 0;
        } catch (e) {
            if (e?.response?.status === 404 && s.activeId === id) closeOutOfScope();
        }
    }

    async function open(id, { read = true } = {}) {
        const mine = ++seq;
        if (id !== s.activeId) s.threadFilters = {};
        s.activeId = id;
        s.view = 'thread';
        s.sendError = '';
        s.notice = '';
        leaveDetached();
        try {
            const { data } = await axios.get(threadUrl(id, 'messages'), { ...api, params: filterParams() });
            if (mine !== seq) return;
            Object.assign(s, { thread: data.conversation, messages: data.messages || [], notes: data.notes || [], hasMore: !!data.has_more, newBelow: 0, filtered: !!data.filtered });
            syncedAt = data.synced_at;
            await thread()?.toBottom();
            if (read) markRead(id);
            load();
        } catch (e) {
            if (mine !== seq) return;
            if (gone(e)) return closeOutOfScope();
            if (s.thread?.id === id) s.sendError = 'تعذّر تحميل المحادثة';
            else {
                resetThread();
                s.notice = 'تعذّر تحميل المحادثة، حاول مرة أخرى.';
            }
        }
    }

    /**
     * القفز إلى رسالة (E30): نافذة حولها (50 قبلها و49 بعدها)، تُرسم ثم تُوسَّط وتُبرز. إن بقي بعدها ما لم يُحمَّل
     * فالخيط «منفصل عن الأحدث»: الاستطلاع لا يلحق الجديد ولا يطبّق gap فلا يُسحب الموظف بعيداً عنها (C143).
     */
    async function openAround(id, msgId) {
        const mine = ++seq;
        if (id !== s.activeId) s.threadFilters = {};
        s.activeId = id;
        s.view = 'thread';
        s.sendError = '';
        s.notice = '';
        leaveDetached();
        try {
            const { data } = await axios.get(threadUrl(id, 'messages'), { ...api, params: { around: msgId, ...filterParams() } });
            if (mine !== seq) return;
            Object.assign(s, {
                thread: data.conversation, messages: data.messages || [], notes: data.notes || [], hasMore: !!data.has_more_before,
                newBelow: 0, filtered: !!data.filtered, detached: !!data.detached, anchorId: data.anchor_id ?? msgId,
            });
            latestKnown = data.latest_id ?? lastId(s.messages);
            syncedAt = data.synced_at;
            await thread()?.scrollToMessage?.(msgId);
            load();
        } catch (e) {
            if (mine !== seq) return;
            if (gone(e)) return closeOutOfScope();
            resetThread();
            s.notice = 'تعذّر فتح الرسالة، حاول مرة أخرى.';
        }
    }

    /** الوضع المنفصل: النزول بصفحات بعد آخر رسالة معروضة حتى الأحدث، فيعود الخيط حياً */
    async function loadNewer() {
        const id = s.activeId;
        if (!id || !s.detached || s.loadingNewer) return;
        const mine = seq;
        s.loadingNewer = true;
        try {
            const { data } = await axios.get(threadUrl(id, 'messages'), { ...api, params: { after: lastId(s.messages), limit: 100, ...filterParams() } });
            if (mine !== seq) return;
            s.messages = mergeNewer(s.messages, data.messages).messages;
            if (!data.has_more_after) leaveDetached();
        } catch (e) {
            if (mine === seq && gone(e)) closeOutOfScope();
        } finally {
            if (mine === seq) s.loadingNewer = false;
        }
    }

    /** «⬇ العودة إلى الأحدث»: يُعاد فتح المحادثة من آخرها */
    const backToLatest = () => (s.activeId ? open(s.activeId, { read: true }) : undefined);

    /** فلاتر الخيط (E30): المصدر والنوع والفترة. تُطبَّق بإعادة التحميل من الأحدث */
    function setThreadFilters(filters) {
        s.threadFilters = Object.fromEntries(Object.entries(filters || {}).filter(([, v]) => v));
        if (s.activeId) return open(s.activeId, { read: false });
    }
    const clearThreadFilters = () => setThreadFilters({});

    const viewing = () => document.visibilityState === 'visible' && document.hasFocus();

    /** الوضع المنفصل: حالات الصادر في النافذة تتحدّث، والجديد يُعدّ فقط (زر «العودة إلى الأحدث») */
    async function pollDetached(id, mine) {
        const { data } = await axios.get(threadUrl(id, 'messages'), {
            ...api, params: { after: latestKnown || lastId(s.messages), since: syncedAt || undefined, limit: 100, ...filterParams() },
        });
        if (mine !== seq || !s.detached) return;
        applyUpdates(s.messages, data.updates);
        s.thread = { ...s.thread, ...data.conversation };
        syncedAt = data.synced_at;
        const fresh = data.messages || [];
        if (fresh.length) {
            latestKnown = fresh[fresh.length - 1].id;
            s.pendingNew += fresh.length;
        }
        if (data.has_more_after) s.pendingMore = true;
    }

    async function pollOnce() {
        const id = s.activeId;
        if (!id || !s.thread) return;
        const mine = seq;
        try {
            if (s.detached) return await pollDetached(id, mine);
            const { data } = await axios.get(threadUrl(id, 'messages'), { ...api, params: { after: lastId(s.messages), since: syncedAt || undefined, ...filterParams() } });
            if (mine !== seq) return;
            if (needsReload(data)) return open(id, { read: false });
            const atBottom = thread()?.nearBottom() ?? true;
            applyUpdates(s.messages, data.updates);
            const { messages, fresh } = mergeNewer(s.messages, data.messages);
            s.messages = messages;
            s.thread = { ...s.thread, ...data.conversation };
            syncedAt = data.synced_at;
            if (fresh.length && !atBottom) s.newBelow += fresh.length;
            else {
                if (fresh.length) await thread()?.toBottom();
                if (viewing()) markRead(id);
            }
        } catch (e) {
            if (mine === seq && gone(e)) closeOutOfScope();
        }
    }

    // طلب واحد في كل مرة، حتى لا يُضاف ردّ قديم فوق ردّ أحدث
    async function poll() {
        while (inflight) await inflight;
        inflight = pollOnce().finally(() => { inflight = null; });
        return inflight;
    }

    async function loadOlder() {
        const id = s.activeId;
        const before = s.messages[0]?.id;
        if (!id || !before || !s.hasMore || s.loadingOlder) return;
        const mine = seq;
        s.loadingOlder = true;
        try {
            const { data } = await axios.get(threadUrl(id, 'messages'), { ...api, params: { before, ...filterParams() } });
            if (mine !== seq) return;
            await (thread()?.keepOnPrepend(() => {
                s.messages = mergeOlder(s.messages, data.messages);
                s.hasMore = !!data.has_more;
                s.loadingOlder = false;
            }) ?? Promise.resolve());
        } catch (e) {
            if (mine === seq && gone(e)) closeOutOfScope();
        } finally {
            if (mine === seq) s.loadingOlder = false;
        }
    }

    async function jumpToNew() {
        s.newBelow = 0;
        await thread()?.toBottom();
        markRead(s.activeId);
    }

    function reachedBottom() {
        s.newBelow = 0;
        markRead(s.activeId);
    }

    /** confirmFn(message): Promise<boolean> لتحذير السياسة (E15.9) */
    async function send(confirmFn) {
        const id = s.activeId;
        const body = drafts.get(id).trim();
        if (!id || !body || s.sending) return;
        s.sending = true;
        s.sendError = '';
        const headers = { ...api.headers, 'Idempotency-Key': sendKeys.keyFor(id, body) };
        try {
            let res;
            try {
                res = await axios.post(threadUrl(id, 'send'), { body }, { headers });
            } catch (e) {
                if (e?.response?.status !== 409 || e.response.data?.code !== 'POLICY_WARN') throw e;
                if (!(await confirmFn(e.response.data.error))) {
                    s.sendError = 'لم تُرسل الرسالة.';
                    return;
                }
                res = await axios.post(threadUrl(id, 'send'), { body, ack_warnings: true }, { headers });
            }
            // 202 «غير مؤكد» (دفتر الإرسال E19): المسودة والمفتاح باقيان، ولا إعادة تلقائية. الخيط يُظهر الصف ⚠️
            if (res?.data?.unknown) {
                s.sendError = `${res.data.error || 'قد تكون الرسالة وصلت.'} لم تُمسح المسودة: تحقق من الخيط قبل إعادة الإرسال.`;
                if (s.activeId === id) poll();
                return;
            }
            sendKeys.done(id);
            drafts.clear(id);
            if (s.activeId === id && (s.detached || s.filtered)) {
                // بعد الإرسال من عرض مفلتر أو نافذة قديمة: يُلغى الفلتر ويعود الخيط إلى الأحدث فتظهر الرسالة (E30)
                s.threadFilters = {};
                await open(id, { read: false });
            } else if (s.activeId === id) {
                await poll();
                s.newBelow = 0;
                await thread()?.toBottom();
            }
        } catch (e) {
            if (e?.response?.status === 404 && s.activeId === id) return closeOutOfScope();
            s.sendError = errorText(e, 'تعذّر الإرسال');
            const code = e?.response?.data?.code;
            // المفتاح مستعمل لنص آخر: مفتاح جديد، والضغطة التالية ترسل
            if (code === 'IDEMPOTENCY_MISMATCH') {
                sendKeys.rotate(id);
                s.sendError = 'تغيّر النص منذ المحاولة السابقة — اضغط «إرسال» مرة أخرى.';
            }
            // الرسالة نفسها قيد الإرسال: الخيط يُحدَّث بعد 3 ثوانٍ
            if (code === 'SEND_IN_PROGRESS' && s.activeId === id) setTimeout(() => s.activeId === id && poll(), 3000);
            if (code === 'OUTCOME_UNKNOWN') {
                s.sendError = 'قد تكون هذه الرسالة وصلت من قبل (غير مؤكد). تحقق من الخيط قبل إعادة الإرسال.';
                if (s.activeId === id) poll();
            }
            // ميتا أغلقت النافذة (131047): المنشئ يُعطَّل بشرح النافذة نفسه، والمسودة باقية
            if (e?.response?.data?.category === 'window' && s.activeId === id && s.thread) s.thread.window_open = false;
            // مهلة ميتا (503): قد تكون وصلت، فالخيط يُحدَّث ليظهر صفها «غير مؤكد»
            if (e?.response?.status === 503 && s.activeId === id) poll();
        } finally {
            s.sending = false;
        }
    }

    /** إجراء على المحادثة المفتوحة؛ 404 تعني أنها خرجت من النطاق */
    async function act(action, payload, onDone, fallback, method = 'post') {
        const id = s.activeId;
        if (!id) return false;
        s.sendError = '';
        try {
            const { data } = await axios[method](threadUrl(id, action), ...(method === 'delete' ? [api] : [payload, api]));
            if (s.activeId === id && s.thread) await onDone?.(data, id);
            load();
            return true;
        } catch (e) {
            if (s.activeId !== id) return false;
            if (e?.response?.status === 404) closeOutOfScope();
            else s.sendError = errorText(e, fallback);
            return false;
        }
    }

    // القيمة المطلوبة صراحة (E21): نقرتان على زر قديم الحالة لا تعكسان النتيجة
    const toggleBot = () => act('toggle-bot', { enabled: !s.thread?.bot_enabled }, (d) => { s.thread.bot_enabled = d.bot_enabled; }, 'تعذّر تغيير حالة البوت');
    const resolve = () => act('resolve', {}, () => { s.thread.needs_attention = false; s.thread.attention_reason = null; }, 'تعذّر حفظ المعالجة');
    // إزالة وسم اختصاصك قد تخرج المحادثة من نطاقك: استطلاع فوري
    const setTopic = (key, attach) => key && act('topic', { topic: key, attach }, (d) => { s.thread.topics = d.topics; if (!attach) poll(); }, 'تعذّر تعديل الوسم');
    async function assignTo(el) {
        const ok = await act('assign', { user_id: el.value === '' ? null : Number(el.value) }, () => poll(), 'تعذّر التوكيل');
        if (!ok) el.value = String(s.thread?.assigned_to ?? '');
    }
    async function requestPhone() {
        if (s.requesting) return;
        s.requesting = true;
        try { await act('request-contact', {}, () => poll(), 'تعذّر إرسال طلب الرقم'); } finally { s.requesting = false; }
    }
    function onLinked(data) {
        if (!s.thread) return;
        Object.assign(s.thread, { name: data.client.name, client_id: data.client.id, client: data.client.name, client_code: data.client.code, client_link: data.client_link });
        load();
    }
    const unlinkClient = () => act('link-client', null, () => Object.assign(s.thread, { client_id: null, client: null, client_code: null, client_link: 'blocked' }), 'تعذّر فك الربط', 'delete');

    // ===== متابعة الصندوق (E17): حالة داخلية عندنا، لا شيء يصل العميل أو ميتا =====
    const FOLLOW_FIELDS = ['closed', 'snoozed_until', 'pinned', 'marked_unread', 'marked_unread_by'];
    function applyState(id, data) {
        const picked = Object.fromEntries(FOLLOW_FIELDS.filter((k) => k in data).map((k) => [k, data[k]]));
        if ('unread' in data) picked.unread = data.unread;
        if (s.thread?.id === id) Object.assign(s.thread, picked);
        const row = s.list.find((c) => c.id === id);
        if (row) Object.assign(row, picked);
    }

    async function loadSummary() {
        if (!props.followup) return;
        try {
            const { data } = await axios.get('/api/whatsapp/inbox-summary', api);
            s.summary = data;
        } catch {
            // البطاقات ليست حرجة؛ شريط «تعذّر التحديث» يغطي الانقطاع
        }
    }

    const FOLLOW_ROUTES = {
        close: ['close', 'post'], reopen: ['reopen', 'post'], snooze: ['snooze', 'post'], unsnooze: ['snooze', 'delete'],
        pin: ['pin', 'post'], unpin: ['pin', 'delete'], markUnread: ['mark-unread', 'post'], reprocess: ['reprocess', 'post'],
    };
    const FOLLOW_ERRORS = {
        close: 'تعذّر إغلاق المحادثة', reopen: 'تعذّر إعادة الفتح', snooze: 'تعذّر التأجيل', unsnooze: 'تعذّر إلغاء التأجيل',
        pin: 'تعذّر التثبيت', unpin: 'تعذّر إلغاء التثبيت', markUnread: 'تعذّر التعليم', reprocess: 'تعذّرت إعادة المعالجة',
    };

    /** confirmFn(options): Promise<boolean> لإعادة المعالجة */
    async function followAction(name, payload, confirmFn) {
        const id = s.activeId;
        const route = FOLLOW_ROUTES[name];
        if (!id || !route) return;
        if (name === 'reprocess' && !(await confirmFn({
            title: 'إعادة معالجة الرد العالق',
            message: 'يُعاد تشغيل رد البوت لهذه المحادثة. لا يُقطع رد جارٍ، وتُسجَّل الإعادة في سجل التدقيق.',
            confirmLabel: '🔁 إعادة المعالجة',
        }))) return;

        const ok = await act(route[0], payload || {}, (data) => {
            if (name === 'reprocess') s.notice = data.message || '';
            else applyState(id, data);
        }, FOLLOW_ERRORS[name], route[1]);
        if (!ok) return;
        loadSummary();
        // «غير مقروء»: يُغلق الخيط ويعود الموظف للقائمة، فلا يمحو الفتح التلقائي (POST /read) العلامة
        if (name === 'markUnread') {
            resetThread();
            back();
        }
    }

    // ===== التنقل: على الجوال فتح المحادثة من القائمة خطوة في history، والرجوع (الزر أو الجهاز) يعيد القائمة =====
    const inboxPath = window.location.pathname;
    const urlConv = () => Number(new URLSearchParams(window.location.search).get('conv')) || null;
    const narrow = () => !!window.matchMedia?.('(max-width: 1023.98px)').matches;

    function openFromList(id) {
        setUrl(id, narrow() && s.view === 'list');
        open(id);
    }
    /** نتيجة بحث (E30): المحادثة حول الرسالة، والخطوة في history على الجوال كفتحها من القائمة */
    function openFromSearch(id, msgId) {
        setUrl(id, narrow() && s.view === 'list');
        openAround(id, msgId);
    }
    function back() {
        if (window.history.state?.inboxPushed) window.history.back();
        else { s.view = 'list'; setUrl(null, false); }
    }
    /**
     * يُسجَّل في utils/historyGuard فيسبق Inertia: بين إدخالات الصندوق نفسه نعرض القائمة أو المحادثة من الرابط ونعيد true
     * فيتوقف الحدث، وإلا أعاد Inertia تركيب الصفحة (preserveState: false) فضاعت الصفحات المحمّلة وموضع التمرير.
     * الرجوع إلى صفحة أخرى يبقى لـ Inertia.
     */
    function onPopState() {
        if (window.location.pathname !== inboxPath) return false;
        const id = urlConv();
        if (!id) s.view = 'list';
        else if (id !== s.activeId) open(id);
        else s.view = 'thread';
        return true;
    }
    /**
     * رابط ?conv= من إشعار أو طلب تسعير أو عودة من صفحة أخرى: تُفتح المحادثة بلا تعديل في history،
     * فزر «→ المحادثات» يعرض القائمة، ورجوع الجهاز يعود إلى حيث جاء الموظف.
     */
    function openFromUrl() {
        const id = urlConv();
        const msg = Number(new URLSearchParams(window.location.search).get('msg')) || null;
        if (id && msg) {
            // ?conv=&msg= (E30): قفز إلى الرسالة، ويُزال msg من الرابط فلا يتكرر القفز عند التحديث
            const url = new URL(window.location.href);
            url.searchParams.delete('msg');
            const target = url.pathname + url.search + url.hash;
            window.history.replaceState(withPageUrl(window.history.state, target), '', target);
            openAround(id, msg);
        } else if (id) open(id);
    }

    /**
     * «انسخ إلى خانة الكتابة» لرسالة فشلت أو لم يُتأكد وصولها (E19): النص في المنشئ بمفتاح جديد، فيراجعه الموظف ويرسله بنفسه.
     * لا إعادة إرسال آلية أبداً. confirmFn للحالة «غير مؤكد» (قد تكون وصلت).
     */
    async function copyToDraft(m, confirmFn) {
        const id = s.activeId;
        if (!id || !m?.can_resend) return;
        if (m.status === 'unknown' && !(await confirmFn('قد تكون الرسالة الأولى وصلت. هل تريد الإرسال مرة ثانية؟'))) return;
        sendKeys.rotate(id);
        drafts.set(id, String(m.body || ''));
    }

    return {
        s, draft, cannotReachPhoneless, canRequestPhone, copyToDraft,
        load, loadMore, open, poll, loadOlder, jumpToNew, reachedBottom, send,
        toggleBot, resolve, setTopic, assignTo, requestPhone, onLinked, unlinkClient,
        openFromList, back, onPopState, openFromUrl,
        openAround, openFromSearch, loadNewer, backToLatest, setThreadFilters, clearThreadFilters,
        refilter: () => load({ reset: true }),
        followAction, loadSummary,
        tick: async () => { const ok = await load(); await poll(); loadSummary(); if (!ok) throw new Error('list refresh failed'); },
        dispose: () => { seq++; },
    };
}
