import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import FactCard from '@/Components/WhatsApp/FactCard.vue';

const FACTS = {
    pinned: { note: { text: 'يفضّل الطابق الأرضي', by: 'سارة', at: '2026-10-16 10:00' }, messages: [{ id: 9, excerpt: 'أريد عمرة', at: '2026-10-16 09:00', direction: 'in' }] },
    labels: [],
    client: { name: 'سامي', code: 'CLT-042', url: '/clients?search=CLT-042' },
    next_trip: { date: '2026-11-20', invoice_number: 'INV-1', url: '/invoices?highlight=1' },
    last_invoice: null,
    open_quote: { request_number: 'QR-1', status: 'priced', status_label: 'مسعّر' },
    balance: { amount: '12.500', currency: 'JOD', direction: 'عليه' },
    hint: null,
};

describe('FactCard (E29)', () => {
    beforeEach(() => {
        try { localStorage.clear(); } catch { /* jsdom */ }
    });
    afterEach(() => vi.restoreAllMocks());

    it('says it is internal and shows the note, the pinned messages and the facts', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: FACTS });
        const w = mount(FactCard, { props: { conversationId: 4, canReply: true } });
        await flushPromises();
        expect(w.text()).toContain('🔒 داخلي، لا يراه العميل');
        expect(w.find('[data-test="pinned-note"]').text()).toBe('يفضّل الطابق الأرضي');
        expect(w.text()).toContain('عدّلها سارة');
        const line = w.find('[data-test="facts-line"]');
        expect(line.text()).toContain('✈️ السفر 2026-11-20 · فاتورة INV-1');
        expect(line.text()).toContain('💳 الرصيد: عليه 12.500 JOD');
        expect(line.find('a[href="/invoices?highlight=1"]').exists()).toBe(true);
        expect(w.emitted('pins')[0][0]).toEqual([9]);
        await w.find('ul button').trigger('click');
        expect(w.emitted('jump')[0][0]).toBe(9);
    });

    it('counts up to 500 and shows the guard message the server returns', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: { ...FACTS, pinned: { note: null, messages: [] } } });
        vi.spyOn(axios, 'put').mockRejectedValue({ response: { status: 422, data: { message: 'x', errors: { note: ['لا تكتب أرقام جوازات أو هويات أو بطاقات في الملاحظة المثبتة'] } } } });
        const w = mount(FactCard, { props: { conversationId: 4, canReply: true } });
        await flushPromises();
        await w.find('button.min-h-8').trigger('click');
        const area = w.find('textarea');
        expect(area.attributes('maxlength')).toBe('500');
        await area.setValue('جوازه A12345678');
        expect(w.find('[data-test="note-counter"]').text()).toBe('15 / 500');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(w.find('[data-test="note-error"]').text()).toBe('لا تكتب أرقام جوازات أو هويات أو بطاقات في الملاحظة المثبتة');
        expect(w.find('textarea').exists()).toBe(true);
    });

    it('collapses to one line and remembers it, and hides editing without the reply permission', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: FACTS });
        const w = mount(FactCard, { props: { conversationId: 4, canReply: false } });
        await flushPromises();
        expect(w.findAll('button').some((b) => b.text().includes('تعديل'))).toBe(false);
        await w.find('button[aria-expanded]').trigger('click');
        expect(w.find('[data-test="pinned-note"]').exists()).toBe(false);
        expect(w.text()).toContain('يفضّل الطابق الأرضي');
        expect(localStorage.getItem('wa:factcard:collapsed')).toBe('1');
    });
});
