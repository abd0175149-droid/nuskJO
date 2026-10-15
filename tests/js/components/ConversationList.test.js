import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ConversationList from '@/Components/WhatsApp/ConversationList.vue';

const row = (id, over = {}) => ({ id, name: `عميل ${id}`, phone: '16505551234', unread: 0, last_message: 'مرحبا', ...over });

afterEach(() => vi.useRealTimers());

describe('ConversationList', () => {
    it('shows «load more» only while the server says there is more', async () => {
        const w = mount(ConversationList, { props: { items: [row(1), row(2)], hasMore: true, seesAll: true } });
        const more = w.findAll('button').find((b) => b.text() === 'تحميل المزيد');
        await more.trigger('click');
        expect(w.emitted('loadMore')).toHaveLength(1);

        await w.setProps({ hasMore: false });
        expect(w.text()).not.toContain('تحميل المزيد');
    });

    it('disables «load more» while a page is loading', () => {
        const w = mount(ConversationList, { props: { items: [row(1)], hasMore: true, loadingMore: true, seesAll: true } });
        const btn = w.findAll('button').find((b) => b.text().includes('جارٍ التحميل'));
        expect(btn.attributes('disabled')).toBeDefined();
    });

    it('debounces the search by 400ms', async () => {
        vi.useFakeTimers();
        const w = mount(ConversationList, { props: { items: [], seesAll: true } });
        await w.find('input[type="search"]').setValue('محمد');
        vi.advanceTimersByTime(399);
        expect(w.emitted('update:search')).toBeUndefined();
        vi.advanceTimersByTime(1);
        expect(w.emitted('update:search')[0]).toEqual(['محمد']);
    });

    it('opens a row, marks the active one and formats its phone', async () => {
        const w = mount(ConversationList, { props: { items: [row(7)], activeId: 7, seesAll: false } });
        const item = w.find('[aria-current="true"]');
        expect(item.exists()).toBe(true);
        expect(item.find('bdi[dir="ltr"]').text()).toBe('+1 650 555 1234');
        await item.trigger('click');
        expect(w.emitted('open')[0]).toEqual([7]);
        expect(w.text()).toContain('تظهر لك المحادثات التي تخصّ مجالاتك فقط');
    });
});
