import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ThreadView from '@/Components/WhatsApp/ThreadView.vue';

const stubs = { ThreadHeader: true, Composer: true, MediaChip: true };
const thread = (over = {}) => ({ id: 3, window_open: true, topics: [], ...over });
const messages = [
    { id: 10, direction: 'in', source: 'customer', body: 'مرحبا', at: '10:00' },
    { id: 11, direction: 'out', source: 'bot', body: 'أهلاً', at: '10:01' },
    { id: 12, direction: 'in', source: 'customer', body: 'سؤال', at: '10:02' },
];

describe('ThreadView read receipts (E35)', () => {
    it('passes what Meta was told to the bubbles', () => {
        const w = mount(ThreadView, { props: { thread: thread({ read_receipt_upto_id: 10 }), messages }, global: { stubs } });
        expect(w.findAll('[data-test="read-receipt"]')).toHaveLength(1);
    });

    it('warns the assignee only when opening shows the customer a read receipt', () => {
        const on = mount(ThreadView, { props: { thread: thread({ receipts_open_notice: true }), messages }, global: { stubs } });
        expect(on.find('[data-test="open-receipt-notice"]').text()).toContain('فتحك لهذه المحادثة يُظهر للعميل أنك قرأت رسائله');
        const off = mount(ThreadView, { props: { thread: thread({ receipts_open_notice: false }), messages }, global: { stubs } });
        expect(off.find('[data-test="open-receipt-notice"]').exists()).toBe(false);
    });
});
