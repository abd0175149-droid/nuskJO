import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// الوضع المنفصل عن الأحدث (E30.8): القفز إلى رسالة قديمة لا يسحبه الاستطلاع، والجديد يُعدّ فقط، والنزول يعيد
// الخيط حياً، والفلتر يُلغى بعد الإرسال، ورد قائمة قديم لا يغلب أحدث منه
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
const axios = (await import('axios')).default;
const { useInbox } = await import('@/composables/whatsapp/useInbox.js');

const msg = (id, over = {}) => ({ id, direction: 'in', source: 'customer', body: `m${id}`, at: '2026-10-01 10:00', ...over });
const range = (a, b) => Array.from({ length: b - a + 1 }, (_, i) => msg(a + i));
const conversation = { id: 5, name: 'سامي', unread: 0, window_open: true };

function threadStub() {
    return { nearBottom: () => true, toBottom: vi.fn(async () => {}), keepOnPrepend: async (fn) => fn(), scrollToMessage: vi.fn(async () => {}) };
}

function setup() {
    const view = threadStub();
    const inbox = useInbox({ conversations: [], listMeta: {}, filters: {}, canReply: true, identityMode: 'staff' }, () => view);
    return { inbox, s: inbox.s, view };
}

/** ردود الخادم بحسب المسار والمعاملات */
function serve(handler) {
    axios.get.mockImplementation(async (url, config = {}) => ({ data: handler(url, config.params || {}) }));
}

beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/whatsapp/inbox');
    axios.post.mockResolvedValue({ data: {} });
});
afterEach(() => vi.useRealTimers());

describe('jumping to a message', () => {
    it('opens the window around the message, centres it and stays detached while polling', async () => {
        serve((url, p) => {
            if (url === '/api/whatsapp/conversations') return { conversations: [], has_more: false };
            if (p.around) return { messages: range(50, 149), has_more_before: true, has_more_after: true, detached: true, anchor_id: 100, latest_id: 300, conversation, synced_at: 't0' };
            if (p.after === 300) return { messages: [msg(301), msg(302)], updates: [{ id: 120, status: 'read', error: null }], has_more_after: false, conversation, synced_at: 't1' };
            throw new Error(`unexpected ${url} ${JSON.stringify(p)}`);
        });
        const { inbox, s, view } = setup();

        await inbox.openAround(5, 100);
        expect(view.scrollToMessage).toHaveBeenCalledWith(100);
        expect(s.detached).toBe(true);
        expect(s.anchorId).toBe(100);
        const shown = s.messages.map((m) => m.id);

        await inbox.poll();
        expect(s.messages.map((m) => m.id)).toEqual(shown);
        expect(s.pendingNew).toBe(2);
        expect(s.messages.find((m) => m.id === 120).status).toBe('read');
        expect(axios.get.mock.calls.at(-1)[1].params).toMatchObject({ after: 300, limit: 100 });
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('scrolling down loads pages until the latest, then the thread is live again', async () => {
        serve((url, p) => {
            if (url === '/api/whatsapp/conversations') return { conversations: [] };
            if (p.around) return { messages: range(50, 149), has_more_before: true, has_more_after: true, detached: true, anchor_id: 100, latest_id: 260, conversation, synced_at: 't0' };
            if (p.after === 149) return { messages: range(150, 249), has_more_after: true, conversation };
            if (p.after === 249) return { messages: range(250, 260), has_more_after: false, conversation };
            throw new Error(`unexpected ${JSON.stringify(p)}`);
        });
        const { inbox, s } = setup();
        await inbox.openAround(5, 100);

        await inbox.loadNewer();
        expect(s.detached).toBe(true);
        await inbox.loadNewer();
        expect(s.detached).toBe(false);
        expect(s.messages.at(-1).id).toBe(260);
        expect(s.messages).toHaveLength(211);
    });

    it('«back to latest» reloads from the newest message', async () => {
        serve((url, p) => {
            if (url === '/api/whatsapp/conversations') return { conversations: [] };
            if (p.around) return { messages: range(1, 60), has_more_after: true, detached: true, anchor_id: 10, latest_id: 400, conversation };
            return { messages: range(301, 400), has_more: true, conversation, synced_at: 't2' };
        });
        const { inbox, s, view } = setup();
        await inbox.openAround(5, 10);
        await inbox.backToLatest();

        expect(s.detached).toBe(false);
        expect(s.messages.at(-1).id).toBe(400);
        expect(view.toBottom).toHaveBeenCalled();
    });

    it('a deep link ?conv=&msg= jumps once and drops msg from the address', async () => {
        window.history.replaceState({}, '', '/whatsapp/inbox?conv=5&msg=42');
        serve((url, p) => (url === '/api/whatsapp/conversations' ? { conversations: [] }
            : { messages: range(1, 60), has_more_after: false, detached: false, anchor_id: p.around, latest_id: 60, conversation }));
        const { inbox, s } = setup();

        inbox.openFromUrl();
        await vi.waitFor(() => expect(s.anchorId).toBe(42));
        expect(window.location.search).toBe('?conv=5');
    });
});

describe('thread filters', () => {
    it('apply on every request and are cleared after sending', async () => {
        serve((url, p) => {
            if (url === '/api/whatsapp/conversations') return { conversations: [] };
            return { messages: p.source ? [msg(7, { source: 'bot', direction: 'out' })] : range(1, 3), has_more: false, filtered: !!p.source, conversation, synced_at: 't' };
        });
        axios.post.mockResolvedValue({ data: { status: 'sent' } });
        const { inbox, s } = setup();
        await inbox.open(5);

        await inbox.setThreadFilters({ source: 'bot', msg_type: '' });
        expect(axios.get.mock.calls.at(-2)[1].params).toEqual({ source: 'bot' });
        expect(s.filtered).toBe(true);

        inbox.draft.value = 'رد الموظف';
        await inbox.send(async () => true);
        expect(s.threadFilters).toEqual({});
        expect(s.filtered).toBe(false);
    });
});

describe('conversation list ordering', () => {
    it('a slow older list response never overwrites a newer one', async () => {
        let release;
        const slow = new Promise((resolve) => { release = resolve; });
        axios.get.mockImplementationOnce(() => slow)
            .mockImplementationOnce(async () => ({ data: { conversations: [{ id: 2, name: 'نتيجة البحث' }], has_more: false } }));
        const { inbox, s } = setup();

        const old = inbox.load();
        s.search = 'سامي';
        await inbox.refilter();
        release({ data: { conversations: [{ id: 1, name: 'قديم' }, { id: 3, name: 'قديم' }], has_more: false } });
        await old;

        expect(s.list.map((c) => c.id)).toEqual([2]);
    });
});
