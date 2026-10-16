import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import ThreadActionsMenu from '@/Components/WhatsApp/ThreadActionsMenu.vue';

const thread = (over = {}) => ({ id: 1, window_left: 120, closed: false, pinned: false, snoozed_until: null, stuck: false, ...over });
const wrappers = [];
const make = (props) => {
    const w = mount(ThreadActionsMenu, { props: { canReply: true, ...props }, attachTo: document.body });
    wrappers.push(w);
    return w;
};
const item = (w, text) => w.findAll('[role="menuitem"]').find((b) => b.text().includes(text));

afterEach(() => { while (wrappers.length) wrappers.pop().unmount(); });

describe('ThreadActionsMenu', () => {
    it('offers snooze options within the window and disables the rest', async () => {
        const w = make({ thread: thread() });
        await w.find('button[aria-label="إجراءات المحادثة"]').trigger('click');
        expect(item(w, 'ساعة').attributes('disabled')).toBeUndefined();
        expect(item(w, '3 ساعات').attributes('disabled')).toBeDefined();

        await item(w, 'ساعة').trigger('click');
        expect(w.emitted('action')[0]).toEqual(['snooze', { minutes: 60 }]);
    });

    it('explains why snoozing is impossible once the window is closed', async () => {
        const w = make({ thread: thread({ window_left: null, closed: true }) });
        await w.find('button[aria-label="إجراءات المحادثة"]').trigger('click');
        expect(w.text()).toContain('لا يمكن التأجيل بعد إغلاق النافذة');
        expect(item(w, 'إعادة فتح')).toBeTruthy();
    });

    it('shows reprocess only to admins and only for a stuck conversation', async () => {
        const w = make({ thread: thread(), canReprocess: true });
        await w.find('button[aria-label="إجراءات المحادثة"]').trigger('click');
        expect(item(w, 'إعادة معالجة')).toBeUndefined();

        const stuck = make({ thread: thread({ stuck: true }), canReprocess: true });
        await stuck.find('button[aria-label="إجراءات المحادثة"]').trigger('click');
        await item(stuck, 'إعادة معالجة').trigger('click');
        expect(stuck.emitted('action')[0]).toEqual(['reprocess', undefined]);
    });
});
