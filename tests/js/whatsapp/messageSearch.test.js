import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMessageSearch, filterParams, searchError } from '@/composables/whatsapp/useMessageSearch.js';

// البحث في الرسائل (E30.7): تأخير، وتجاهل الردود القديمة، و«المزيد» بلا تكرار، وEscape يلغي
const hit = (id, conv = 1) => ({ message_id: id, conversation_id: conv, conversation_name: 'عميل', at: '2026-10-01 10:00', source: 'customer', segments: [{ t: 'نص', hl: true }] });

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

describe('createMessageSearch', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('waits 300ms after the last keystroke and sends one request', async () => {
        const get = vi.fn().mockResolvedValue({ data: { hits: [hit(1)], total: 1, took_ms: 4, engine: 'fts' } });
        const search = createMessageSearch({ get });

        search.setQuery('عمر');
        vi.advanceTimersByTime(200);
        search.setQuery('عمرة');
        vi.advanceTimersByTime(299);
        expect(get).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        await vi.runAllTimersAsync();

        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0]).toBe('/api/whatsapp/search');
        expect(get.mock.calls[0][1].params).toEqual({ q: 'عمرة', limit: 20 });
        expect(search.state.hits).toHaveLength(1);
        expect(search.state.tookMs).toBe(4);
    });

    it('never applies a stale response over a newer one and aborts the older request', async () => {
        const first = deferred();
        const second = deferred();
        const signals = [];
        const get = vi.fn((url, config) => { signals.push(config.signal); return get.mock.calls.length === 1 ? first.promise : second.promise; });
        const search = createMessageSearch({ get, delay: 0 });

        search.setQuery('فندق');
        await vi.runAllTimersAsync();
        search.setQuery('فندق مكة');
        await vi.runAllTimersAsync();
        expect(signals[0].aborted).toBe(true);

        second.resolve({ data: { hits: [hit(2)], total: 1 } });
        await vi.runAllTimersAsync();
        first.resolve({ data: { hits: [hit(1)], total: 1 } });
        await vi.runAllTimersAsync();

        expect(search.state.hits.map((h) => h.message_id)).toEqual([2]);
    });

    it('loads more by offset without duplicates and stops at the total', async () => {
        const get = vi.fn()
            .mockResolvedValueOnce({ data: { hits: [hit(1), hit(2)], total: 3 } })
            .mockResolvedValueOnce({ data: { hits: [hit(2), hit(3)], total: 3 } });
        const search = createMessageSearch({ get, delay: 0 });

        search.setQuery('حجز');
        await vi.runAllTimersAsync();
        await search.loadMore();

        expect(get.mock.calls[1][1].params.offset).toBe(2);
        expect(search.state.hits.map((h) => h.message_id)).toEqual([1, 2, 3]);
        expect(search.canLoadMore()).toBe(false);
        await search.loadMore();
        expect(get).toHaveBeenCalledTimes(2);
    });

    it('asks for three letters without a request, unless searching one conversation', async () => {
        const get = vi.fn().mockResolvedValue({ data: { hits: [], total: 0 } });
        const search = createMessageSearch({ get, delay: 0 });

        search.setQuery('رح');
        await vi.runAllTimersAsync();
        expect(get).not.toHaveBeenCalled();
        expect(search.state.hint).toBe('اكتب 3 أحرف على الأقل');

        search.setFilters({ conversation_id: 7 });
        await vi.runAllTimersAsync();
        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][1].params).toMatchObject({ q: 'رح', conversation_id: 7 });
        expect(search.state.hint).toBe('');
    });

    it('cancel (Escape) aborts the pending request and closes the results', async () => {
        const pending = deferred();
        let signal;
        const get = vi.fn((url, config) => { signal = config.signal; return pending.promise; });
        const search = createMessageSearch({ get, delay: 0 });

        search.setQuery('تأشيرة');
        await vi.runAllTimersAsync();
        expect(search.state.loading).toBe(true);
        expect(search.cancel()).toBe(true);
        expect(signal.aborted).toBe(true);
        pending.resolve({ data: { hits: [hit(9)], total: 1 } });
        await vi.runAllTimersAsync();

        expect(search.state.hits).toEqual([]);
        expect(search.state.loading).toBe(false);
        expect(search.cancel()).toBe(false);
    });

    it('explains throttling and validation errors in Arabic', async () => {
        const get = vi.fn().mockRejectedValue({ response: { status: 429 } });
        const search = createMessageSearch({ get, delay: 0 });
        search.setQuery('جواز');
        await vi.runAllTimersAsync();

        expect(search.state.error).toBe('بحثت كثيراً خلال دقيقة؛ انتظر قليلاً');
        expect(searchError({ response: { status: 422, data: { errors: { q: ['اكتب حرفين على الأقل'] } } } })).toBe('اكتب حرفين على الأقل');
    });

    it('maps UI filters to server parameters', () => {
        expect(filterParams({ source: 'bot', direction: '', type: 'media', date_from: '2026-10-01', here: true, conversation_id: 3 }))
            .toEqual({ source: 'bot', msg_type: 'image,document,video,sticker', date_from: '2026-10-01', conversation_id: 3 });
    });
});
