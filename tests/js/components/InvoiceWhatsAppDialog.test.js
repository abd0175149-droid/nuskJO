import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

import axios from 'axios';
import InvoiceWhatsAppDialog from '@/Components/Invoices/InvoiceWhatsAppDialog.vue';

// إرسال الفاتورة على واتساب (E32.9): محادثات عميل الفاتورة وحدها، وسطر تأكيد بالرقم وآخر 4 أرقام، ومفتاح لكل محاولة
const invoice = { id: 7, invoice_number: 'INV-0007', status: 'approved' };
const targets = (list) => ({ data: { sendable: true, filename: 'فاتورة-INV-0007.pdf', caption: 'فاتورتك من الوكالة', targets: list } });
const open3 = { conversation_id: 3, name: 'سالم', last4: '1234', client_code: 'C-1', window_open: true, window_left: 7200 };
const closed4 = { conversation_id: 4, name: 'سالم', last4: '3333', client_code: 'C-1', window_open: false, window_left: null };
const refused = (status, data = {}) => Object.assign(new Error('refused'), { response: { status, data } });
const mounted = [];

async function make(list = [open3, closed4]) {
    axios.get.mockResolvedValue(targets(list));
    const w = mount(InvoiceWhatsAppDialog, { props: { open: true, invoice }, attachTo: document.body });
    mounted.push(w);
    await flushPromises();
    return w;
}
const sendButton = (w) => w.find('[data-test="send-invoice"]');
const keyOf = (call) => call[2].headers['Idempotency-Key'];

beforeEach(() => {
    axios.get.mockReset();
    axios.post.mockReset();
});
afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    document.body.innerHTML = '';
});

describe('InvoiceWhatsAppDialog', () => {
    it('lists the client conversations and picks the only open one, with a clear confirmation line', async () => {
        const w = await make();
        expect(axios.get).toHaveBeenCalledWith('/api/invoices/7/whatsapp-targets', expect.anything());
        const radios = w.findAll('input[type="radio"]');
        expect(radios).toHaveLength(2);
        expect(radios[0].element.checked).toBe(true);
        expect(radios[1].attributes('disabled')).toBeDefined();
        const line = w.find('[data-test="confirm-line"]').text();
        expect(line).toContain('INV-0007');
        expect(line).toContain('سالم');
        expect(line).toContain('…1234');
        expect(w.text()).toContain('فاتورة-INV-0007.pdf');
        expect(w.find('textarea').element.value).toBe('فاتورتك من الوكالة');
    });

    it('posts the confirmed conversation with an Idempotency-Key and links to it', async () => {
        const w = await make();
        axios.post.mockResolvedValue({ status: 202, data: { ok: true, status: 'queued', conversation_id: 3 } });
        await sendButton(w).trigger('click');
        await flushPromises();
        const [url, body, config] = axios.post.mock.calls[0];
        expect(url).toBe('/api/invoices/7/send-whatsapp');
        expect(body).toMatchObject({ conversation_id: 3, confirm_conversation_id: 3, caption: 'فاتورتك من الوكالة' });
        expect(config.headers['Idempotency-Key']).toBeTruthy();
        expect(w.find('a[href="/whatsapp/inbox?conv=3"]').exists()).toBe(true);
    });

    it('keeps the key after a network failure and renews it after a server answer', async () => {
        const w = await make();
        axios.post.mockRejectedValueOnce(new Error('Network Error'));
        await sendButton(w).trigger('click');
        await flushPromises();
        expect(w.text()).toContain('انقطع الاتصال');
        expect(sendButton(w).text()).toContain('إعادة المحاولة');

        axios.post.mockRejectedValueOnce(refused(422, { error: 'لا تُرسل فاتورة غير معتمدة.', code: 'INVOICE_NOT_SENDABLE' }));
        await sendButton(w).trigger('click');
        await flushPromises();
        expect(w.text()).toContain('لا تُرسل فاتورة غير معتمدة.');

        axios.post.mockResolvedValueOnce({ status: 202, data: { ok: true } });
        await sendButton(w).trigger('click');
        await flushPromises();
        const keys = axios.post.mock.calls.map(keyOf);
        expect(keys[1]).toBe(keys[0]);
        expect(keys[2]).not.toBe(keys[1]);
    });

    it('cannot send without an open conversation, and says why', async () => {
        axios.get.mockResolvedValue({ data: { sendable: true, filename: 'x.pdf', caption: '', targets: [], message: 'لا محادثة مفتوحة لعميل هذه الفاتورة.' } });
        const w = mount(InvoiceWhatsAppDialog, { props: { open: true, invoice }, attachTo: document.body });
        mounted.push(w);
        await flushPromises();
        expect(w.find('[data-test="no-targets"]').text()).toContain('لا محادثة مفتوحة');
        expect(sendButton(w).attributes('disabled')).toBeDefined();
    });
});
