import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import InboxCards from '@/Components/WhatsApp/InboxCards.vue';

const summary = { waiting_staff: 3, window_closing: 1, failed_today: 2, failed_breakdown: { failed: 1, unknown: 1 }, channel: { state: 'ok' } };

describe('InboxCards', () => {
    it('shows counts and applies the matching filter on click, toggling back to all', async () => {
        const w = mount(InboxCards, { props: { summary } });
        expect(w.text()).toContain('ينتظر موظفاً');
        expect(w.text()).toContain('القناة تعمل');
        const closing = w.findAll('button').find((b) => b.text().includes('نافذة تُغلق'));
        await closing.trigger('click');
        expect(w.emitted('filter')[0]).toEqual(['window_closing']);

        await w.setProps({ active: 'window_closing' });
        await closing.trigger('click');
        expect(w.emitted('filter')[1]).toEqual(['all']);
    });

    it('explains failures in the tooltip and shows the stuck card only to admins', () => {
        const w = mount(InboxCards, { props: { summary } });
        expect(w.findAll('button').find((b) => b.text().includes('فشل اليوم')).attributes('title')).toBe('فشل: 1 · غير مؤكد: 1');
        expect(w.text()).not.toContain('عالقة');

        const admin = mount(InboxCards, { props: { summary: { ...summary, stuck: 2, failed_jobs: 1, channel: { state: 'suspended' } } } });
        expect(admin.text()).toContain('عالقة');
        expect(admin.text()).toContain('الإرسال موقوف');
    });
});
