import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({ default: { post: vi.fn(), put: vi.fn() } }));

import axios from 'axios';
import EndpointForm from '@/Components/Integrations/EndpointForm.vue';

// نموذج وجهة التكامل (E40.7): لكل حدث الحقول التي ستخرج حرفياً، والمبلغ بخيار صريح، وأخطاء الحارس تحت الرابط
const catalog = [
    { key: 'quote_request.created', label: 'طلب تسعير أو حجز جديد', fields: ['request_number', 'type'], amount_fields: [] },
    { key: 'invoice.approved', label: 'اعتماد فاتورة', fields: ['invoice_number', 'total_sell_jod'], amount_fields: ['total_sell_jod'] },
];
const mounted = [];
const make = (props = {}) => {
    const w = mount(EndpointForm, { props: { open: true, catalog, ...props }, attachTo: document.body });
    mounted.push(w);
    return w;
};

beforeEach(() => { axios.post.mockReset(); axios.put.mockReset(); });
afterEach(() => { while (mounted.length) mounted.pop().unmount(); document.body.innerHTML = ''; });

describe('EndpointForm', () => {
    it('shows the exact fields per event and the amount only when chosen', async () => {
        const w = make();
        expect(w.find('[data-test="never-leaves"]').text()).toContain('لا نصوص رسائل ولا هواتف ولا تكاليف');
        const invoiceFields = () => w.findAll('[data-test="fields"]')[1].text();
        expect(invoiceFields()).toContain('invoice_number');
        expect(invoiceFields()).not.toContain('total_sell_jod');
        await w.find('[data-test="amounts"]').setValue(true);
        expect(invoiceFields()).toContain('total_sell_jod');
    });

    it('creates with the chosen events and reports the saved endpoint and secret', async () => {
        axios.post.mockResolvedValue({ data: { endpoint: { id: 1, name: 'n8n' }, secret: 'whsec_x' } });
        const w = make();
        await w.find('#ep-name').setValue('n8n');
        await w.find('#ep-url').setValue('https://hooks.example.com/x');
        await w.findAll('input[type="checkbox"]')[1].setValue(true);
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/settings/integrations/endpoints', { name: 'n8n', url: 'https://hooks.example.com/x', events: ['invoice.approved'], include_amounts: false });
        expect(w.emitted('saved')[0][0].secret).toBe('whsec_x');
    });

    it('shows the guard message under the url and keeps the form open', async () => {
        axios.post.mockRejectedValue({ response: { status: 422, data: { errors: { url: ['العنوان يحل إلى شبكة خاصة أو محجوزة.'] } } } });
        const w = make();
        await w.find('#ep-name').setValue('x');
        await w.find('#ep-url').setValue('https://internal.example.com/x');
        await w.findAll('input[type="checkbox"]')[0].setValue(true);
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(w.find('[data-test="url-error"]').text()).toContain('شبكة خاصة');
        expect(w.emitted('saved')).toBeUndefined();
    });

    it('edits without asking for the url again', async () => {
        axios.put.mockResolvedValue({ data: { endpoint: { id: 3 } } });
        const w = make({ endpoint: { id: 3, name: 'قديم', events: ['quote_request.created'], include_amounts: false } });
        expect(w.find('#ep-url').attributes('required')).toBeUndefined();
        await w.find('#ep-name').setValue('جديد');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(axios.put).toHaveBeenCalledWith('/settings/integrations/endpoints/3', { name: 'جديد', url: '', events: ['quote_request.created'], include_amounts: false });
    });
});
