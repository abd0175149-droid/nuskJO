import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { reload: vi.fn() },
    usePage: () => ({ props: { auth: { isAdmin: true } } }),
}));

const { default: Blocks } = await import('@/Pages/WhatsApp/Blocks.vue');
const { confirmState } = await import('@/composables/useConfirm');

// صفحة المحظورين (E31): القائم والسجل، والمزامنة القرائية ونتيجتها، وفك الحظر بسبب
const block = (over = {}) => ({
    id: 1, conversation_id: 7, name: 'عميل مزعج', last4: '1234', kind: 'phone', reason_code: 'spam', reason_label: 'إزعاج متكرر',
    reason: 'رسائل كل دقيقة', by: 'المدير', via: 'dashboard', blocked_at: '2026-10-15T10:00:00+03:00', meta_state: 'blocked',
    meta_checked_at: null, meta_error_code: null, window_open: true, unblocked_at: null, unblocked_by: null, unblock_reason: null, ...over,
});
const layout = { template: '<div><slot name="header" /><slot /></div>' };
const stubs = { SmartLayout: layout, AppLayout: layout, EmployeeLayout: layout, AppModal: true };
const make = (props = {}) => mount(Blocks, { props: { active: [block()], history: [], lastSync: null, reasons: { spam: 'إزعاج متكرر', other: 'أخرى' }, channelReady: true, ...props }, global: { stubs } });

describe('Blocks page', () => {
    afterEach(() => vi.restoreAllMocks());

    it('lists active blocks with reason and actor, and the history tab', async () => {
        const w = make({ history: [block({ id: 2, unblocked_at: '2026-10-16T09:00:00+03:00', unblocked_by: 'المدير', unblock_reason: 'سوء فهم', meta_state: 'unblocked' })] });
        const rows = w.find('[data-test="rows-active"]');
        expect(rows.text()).toContain('عميل مزعج');
        expect(rows.text()).toContain('…1234');
        expect(rows.text()).toContain('إزعاج متكرر: رسائل كل دقيقة');
        expect(rows.text()).toContain('حظره المدير من الصندوق');
        expect(rows.find('a').attributes('href')).toBe('/whatsapp/inbox?conv=7');

        await w.findAll('[role="tab"]')[1].trigger('click');
        expect(w.find('[data-test="rows-history"]').text()).toContain('سوء فهم');
        expect(w.text()).toContain('لم تُشغَّل المزامنة بعد');
    });

    it('runs the read-only sync and shows what needs a decision', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {
            ok: true, at: '2026-10-16T10:00:00+03:00', trigger: 'manual', meta: 3, local: 1, truncated: false,
            not_found: [{ id: 1, name: 'عميل مزعج', last4: '1234', conversation_id: 7 }],
            untracked: [{ ref: 'abcdefabcdefabcdefabcdef', last4: '3333', kind: 'phone', name: null, conversation_id: null }],
            mismatch: [],
        } });
        const w = make();
        await w.find('[data-test="sync-btn"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/blocks/sync', {}, expect.anything());
        expect(w.find('[data-test="not-found"]').text()).toContain('…1234');
        expect(w.find('[data-test="untracked"]').text()).toContain('…3333');
        expect(w.find('[data-test="sync-summary"]').text()).toContain('عند ميتا 3');
    });

    it('cannot sync before the channel is ready', () => {
        const w = make({ channelReady: false });
        expect(w.find('[data-test="sync-btn"]').attributes('disabled')).toBeDefined();
        expect(w.text()).toContain('القناة غير مهيأة');
    });

    it('unblocks with a written reason', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true } });
        // بلا مضيف للنافذة الموحّدة: prompt المتصفح
        expect(confirmState.hosts).toHaveLength(0);
        vi.spyOn(window, 'prompt').mockReturnValue('  تم الاعتذار  ');
        const w = make();
        await w.find('[data-test="unblock-1"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/blocks/1/unblock', { reason: 'تم الاعتذار' }, expect.anything());
        expect(w.text()).toContain('فُكّ الحظر.');
    });
});
