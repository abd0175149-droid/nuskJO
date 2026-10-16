import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ThreadView from '@/Components/WhatsApp/ThreadView.vue';
import ThreadFilters from '@/Components/WhatsApp/ThreadFilters.vue';

// الخيط بعد القفز (E30.8): الرسالة موسَّطة ومبرزة ثلاث ثوانٍ، وزر «العودة إلى الأحدث» بعدد الجديد، وشريط «عرض مفلتر»
const thread = { id: 5, name: 'سامي', phone: '16505551234', topics: [], window_open: true };
const msgs = Array.from({ length: 5 }, (_, i) => ({ id: i + 1, direction: 'in', source: 'customer', body: `m${i + 1}`, at: '10:00' }));

afterEach(() => vi.useRealTimers());

describe('ThreadView in detached mode', () => {
    it('centres and rings the anchor for three seconds', async () => {
        vi.useFakeTimers();
        Element.prototype.scrollIntoView = vi.fn();
        const w = mount(ThreadView, { props: { thread, messages: msgs, detached: true } });

        await w.vm.scrollToMessage(3);
        expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
        expect(w.find('[data-msg-id="3"] .ring-2').exists()).toBe(true);
        vi.advanceTimersByTime(3000);
        await w.vm.$nextTick();
        expect(w.find('.ring-2').exists()).toBe(false);
    });

    it('offers «back to latest» with the count of new messages', async () => {
        const w = mount(ThreadView, { props: { thread, messages: msgs, detached: true, pendingNew: 3, newBelow: 2 } });
        const back = w.find('[data-test="back-to-latest"]');
        expect(back.text()).toContain('العودة إلى الأحدث');
        expect(back.text()).toContain('(3 جديدة)');
        expect(w.text()).not.toContain('رسائل جديدة (2)');
        await back.trigger('click');
        expect(w.emitted('backToLatest')).toHaveLength(1);

        await w.setProps({ pendingMore: true });
        expect(w.find('[data-test="back-to-latest"]').text()).toContain('3+');
        await w.setProps({ detached: false });
        expect(w.find('[data-test="back-to-latest"]').exists()).toBe(false);
    });

    it('shows the filtered strip and the filters with export for permitted staff', async () => {
        const w = mount(ThreadView, { props: { thread, messages: [], filtered: true, filters: { source: 'bot' }, canFilter: true, canExport: true } });
        expect(w.text()).toContain('عرض مفلتر');
        expect(w.text()).toContain('لا رسائل تطابق الفلتر');
        await w.findAll('button').find((b) => b.text() === 'إلغاء').trigger('click');
        expect(w.emitted('clearFilters')).toHaveLength(1);
        await w.find('[data-test="open-export"]').trigger('click');
        expect(w.emitted('export')).toHaveLength(1);

        const plain = mount(ThreadView, { props: { thread, messages: msgs } });
        expect(plain.find('[data-test="toggle-filters"]').exists()).toBe(false);
        expect(plain.find('[data-test="open-export"]').exists()).toBe(false);
    });
});

describe('ThreadFilters', () => {
    it('emits the chosen source and type, and clears', async () => {
        const w = mount(ThreadFilters, { props: { filters: {} } });
        await w.findAll('button').find((b) => b.text().includes('البوت')).trigger('click');
        expect(w.emitted('apply')[0][0]).toEqual({ source: 'bot' });
        await w.findAll('button').find((b) => b.text().includes('صور وملفات')).trigger('click');
        expect(w.emitted('apply')[1][0]).toEqual({ source: 'bot', msg_type: 'image,document,video,sticker' });

        await w.setProps({ filters: { source: 'bot' } });
        await w.findAll('button').find((b) => b.text() === 'إلغاء الفلتر').trigger('click');
        expect(w.emitted('clear')).toHaveLength(1);
    });
});
