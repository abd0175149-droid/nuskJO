import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

import axios from 'axios';
import DeliveriesPanel from '@/Components/Integrations/DeliveriesPanel.vue';

// سجل التسليمات (E40.7): الفاشلة أولاً، والإعادة للفاشل والمعلّق وحدهما، والخطأ بنص عربي
const row = (extra = {}) => ({ id: 1, endpoint: 'n8n', event: 'invoice.approved', status: 'dead', attempts: 6, last_status_code: 500, last_error: 'http_500',
    created_at: '2026-10-16T10:00:00+03:00', payload: { type: 'invoice.approved', data: { invoice_number: 'INV-1' } }, ...extra });
const mounted = [];
async function make(rows) {
    axios.get.mockResolvedValue({ data: { data: rows, meta: { page: 1, last_page: 1, total: rows.length } } });
    const w = mount(DeliveriesPanel, { props: { labels: { 'invoice.approved': 'اعتماد فاتورة' } }, attachTo: document.body });
    mounted.push(w);
    await flushPromises();
    return w;
}

beforeEach(() => { axios.get.mockReset(); axios.post.mockReset(); });
afterEach(() => { while (mounted.length) mounted.pop().unmount(); document.body.innerHTML = ''; });

describe('DeliveriesPanel', () => {
    it('opens on failed deliveries with Arabic errors and the payload as sent', async () => {
        const w = await make([row()]);
        expect(axios.get).toHaveBeenCalledWith('/api/integrations/deliveries', { params: { page: 1, status: 'dead' } });
        expect(w.text()).toContain('اعتماد فاتورة');
        expect(w.text()).toContain('ردّ المستقبل 500');
        expect(w.find('pre').text()).toContain('INV-1');
    });

    it('offers a redrive only for failed or pending rows, and marks it pending', async () => {
        axios.post.mockResolvedValue({ data: { ok: true } });
        const w = await make([row(), row({ id: 2, status: 'delivered', last_error: null })]);
        const items = w.findAll('[data-test="deliveries"] li');
        expect(items[1].text()).not.toContain('إعادة');
        await items[0].find('button').trigger('click');
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith('/api/integrations/deliveries/1/redrive');
        expect(w.findAll('[data-test="deliveries"] li')[0].text()).toContain('معلّقة');
    });

    it('filters by status', async () => {
        const w = await make([]);
        await w.findAll('[role="group"] button')[3].trigger('click');
        expect(axios.get).toHaveBeenLastCalledWith('/api/integrations/deliveries', { params: { page: 1, status: 'delivered' } });
    });
});
