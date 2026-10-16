import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import MessageSearchPanel from '@/Components/WhatsApp/MessageSearchPanel.vue';

function fakeSearch(over = {}) {
    const state = reactive({ q: '', filters: {}, hits: [], total: 0, capped: false, tookMs: null, engine: null, loading: false, loadingMore: false, error: '', hint: '', ran: false, ...over });
    return {
        state,
        setQuery: vi.fn((q) => { state.q = q; }),
        setFilters: vi.fn(),
        run: vi.fn(),
        loadMore: vi.fn(),
        canLoadMore: () => state.hits.length < state.total,
        cancel: vi.fn(() => { const had = state.ran; state.hits = []; state.ran = false; return had; }),
        dispose: vi.fn(),
    };
}

const hit = (id, over = {}) => ({ message_id: id, conversation_id: 4, conversation_name: 'سامي', at: '2026-10-01 10:00', source: 'customer', segments: [{ t: 'عمرة', hl: true }], ...over });

describe('MessageSearchPanel', () => {
    it('lists hits with their count and jumps to the clicked message', async () => {
        const searcher = fakeSearch({ hits: [hit(1), hit(2, { source: 'bot' })], total: 23, tookMs: 12, ran: true });
        const w = mount(MessageSearchPanel, { props: { searcher } });

        expect(w.find('[data-test="search-count"]').text()).toContain('23 نتيجة');
        expect(w.find('[data-test="search-count"]').text()).toContain('12 ms');
        expect(w.findAll('[data-test="search-hit"]')).toHaveLength(2);
        expect(w.findAll('mark').map((m) => m.text())).toEqual(['عمرة', 'عمرة']);

        await w.findAll('[data-test="search-hit"]')[1].trigger('click');
        expect(w.emitted('jump')[0][0].message_id).toBe(2);
        expect(w.text()).toContain('المزيد');
    });

    it('shows +1000 and a slower-search hint without the index', () => {
        const w = mount(MessageSearchPanel, { props: { searcher: fakeSearch({ hits: [hit(1)], total: 1000, capped: true, ran: true, engine: 'like', tookMs: 80 }) } });
        expect(w.find('[data-test="search-count"]').text()).toContain('+1000');
        expect(w.text()).toContain('بحث أبطأ');
    });

    it('Escape cancels first, then closes the panel', async () => {
        const searcher = fakeSearch({ hits: [hit(1)], total: 1, ran: true });
        const w = mount(MessageSearchPanel, { props: { searcher } });

        await w.find('[data-test="message-search-input"]').trigger('keydown', { key: 'Escape' });
        expect(searcher.cancel).toHaveBeenCalledTimes(1);
        expect(w.emitted('close')).toBeUndefined();

        await w.find('[data-test="message-search-input"]').trigger('keydown', { key: 'Escape' });
        expect(w.emitted('close')).toHaveLength(1);
    });

    it('limits to the open conversation on request', async () => {
        const searcher = fakeSearch();
        const w = mount(MessageSearchPanel, { props: { searcher, activeId: 9 } });

        await w.find('input[type="checkbox"]').setValue(true);
        expect(searcher.setFilters).toHaveBeenLastCalledWith(expect.objectContaining({ conversation_id: 9 }));

        await w.findAll('select')[0].setValue('staff');
        expect(searcher.setFilters).toHaveBeenLastCalledWith(expect.objectContaining({ source: 'staff', conversation_id: 9 }));
    });

    it('types into the search', async () => {
        const searcher = fakeSearch();
        const w = mount(MessageSearchPanel, { props: { searcher } });
        await w.find('[data-test="message-search-input"]').setValue('فندق');
        expect(searcher.setQuery).toHaveBeenLastCalledWith('فندق');
    });
});
