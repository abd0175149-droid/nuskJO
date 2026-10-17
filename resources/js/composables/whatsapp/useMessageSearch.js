// البحث في نص الرسائل (E30.7): تأخير 300ms بعد آخر كتابة، ورد قديم لا يُطبَّق أبداً (عدّاد seq وAbortController
// يلغي السابق)، و«المزيد» بالإزاحة بلا تكرار (Set بمعرّف الرسالة) حتى total أو إزاحة 500، وcancel لـ Escape.
// الخادم يحصر النتائج بالسياج؛ هذا الملف حالة الواجهة وحدها.
import { reactive } from 'vue';
import axios from 'axios';

export const MIN_CHARS = 3;
export const MAX_OFFSET = 500;
export const PAGE_SIZE = 20;

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };

/** «صور وملفات» و«صوت»: مجموعات أنواع كما يقبلها الخادم (msg_type بفواصل) */
export const TYPE_GROUPS = {
    text: 'text',
    media: 'image,document,video,sticker',
    audio: 'audio',
};

export function searchError(e) {
    const status = e?.response?.status;
    if (status === 429) return 'بحثت كثيراً خلال دقيقة؛ انتظر قليلاً';
    if (status === 404) return 'البحث غير متاح هنا';
    if (status === 422) return Object.values(e.response.data?.errors || {})[0]?.[0] || 'تحقق من نص البحث';
    return 'تعذّر البحث، حاول مرة أخرى';
}

/** فلاتر الواجهة → معاملات الخادم (الفارغ لا يُرسل) */
export function filterParams(f = {}) {
    const out = {
        direction: f.direction || undefined,
        source: f.source || undefined,
        msg_type: TYPE_GROUPS[f.type] || undefined,
        date_from: f.date_from || undefined,
        date_to: f.date_to || undefined,
        conversation_id: f.conversation_id || undefined,
    };
    return Object.fromEntries(Object.entries(out).filter(([, v]) => v !== undefined));
}

const isCancel = (e) => e?.name === 'CanceledError' || e?.name === 'AbortError' || e?.code === 'ERR_CANCELED';

/** @param get بديل axios.get في الاختبار */
export function createMessageSearch({ get = (url, config) => axios.get(url, config), delay = 300 } = {}) {
    const state = reactive({
        q: '', filters: {}, hits: [], total: 0, capped: false, tookMs: null, engine: null,
        loading: false, loadingMore: false, error: '', hint: '', ran: false,
    });
    let seq = 0;
    let controller = null;
    let timer = null;

    const text = () => state.q.trim();
    const tooShort = () => {
        const n = [...text()].length;
        return n < MIN_CHARS && !(n >= 2 && state.filters.conversation_id);
    };

    /** يُلغي الطلب المعلّق، وأي رد يصل بعده يُهمل */
    function abort() {
        seq++;
        controller?.abort();
        controller = null;
        state.loading = false;
        state.loadingMore = false;
    }

    function clearResults() {
        Object.assign(state, { hits: [], total: 0, capped: false, tookMs: null, engine: null, ran: false });
    }

    function schedule() {
        clearTimeout(timer);
        state.error = '';
        if (!text()) {
            abort();
            clearResults();
            state.hint = '';
            return;
        }
        if (tooShort()) {
            abort();
            clearResults();
            state.hint = 'اكتب 3 أحرف على الأقل';
            return;
        }
        state.hint = '';
        timer = setTimeout(() => run(), delay);
    }

    function setQuery(q) {
        state.q = String(q ?? '');
        schedule();
    }

    function setFilters(filters) {
        state.filters = { ...filters };
        schedule();
    }

    const canLoadMore = () => state.hits.length < state.total && state.hits.length <= MAX_OFFSET;

    async function run({ more = false } = {}) {
        clearTimeout(timer);
        timer = null;
        if (!text() || tooShort()) return;
        if (more && !canLoadMore()) return;
        const offset = more ? state.hits.length : 0;
        abort();
        const mine = seq;
        const ctrl = new AbortController();
        controller = ctrl;
        if (more) state.loadingMore = true;
        else state.loading = true;
        state.error = '';
        try {
            const params = { q: text(), limit: PAGE_SIZE, ...filterParams(state.filters), ...(offset ? { offset } : {}) };
            const { data } = await get('/api/whatsapp/search', { ...api, params, signal: ctrl.signal });
            if (mine !== seq) return;
            const incoming = data?.hits || [];
            if (more) {
                const known = new Set(state.hits.map((h) => h.message_id));
                state.hits = [...state.hits, ...incoming.filter((h) => !known.has(h.message_id))];
            } else {
                state.hits = incoming;
            }
            Object.assign(state, { total: data?.total ?? 0, capped: !!data?.total_capped, tookMs: data?.took_ms ?? null, engine: data?.engine || null, ran: true });
        } catch (e) {
            if (mine !== seq || isCancel(e)) return;
            state.error = searchError(e);
        } finally {
            if (mine === seq) {
                state.loading = false;
                state.loadingMore = false;
                controller = null;
            }
        }
    }

    /**
     * Escape: يُلغي المعلّق ويغلق النتائج. يعيد true إن كان هناك ما يُغلق (طلب أو نتائج أو رسالة)، وإلا false
     * (فيغلق المكوّن اللوحة نفسها).
     */
    function cancel() {
        const had = state.loading || state.loadingMore || state.ran || !!state.error || !!state.hint || !!timer;
        clearTimeout(timer);
        timer = null;
        abort();
        clearResults();
        state.error = '';
        state.hint = '';
        return had;
    }

    function dispose() {
        clearTimeout(timer);
        abort();
    }

    return { state, setQuery, setFilters, run, loadMore: () => run({ more: true }), canLoadMore, cancel, dispose };
}
