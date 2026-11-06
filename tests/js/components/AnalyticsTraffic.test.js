import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AnalyticsTraffic from '@/Components/WhatsApp/AnalyticsTraffic.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data) => ({ ...data, put: vi.fn() }),
    usePage: () => ({ props: { auth: { isAdmin: true } } }),
}));

// تبويب «الحركة» ومبدّل الفترة (E24)
const traffic = () => ({
    series: { series: [{ key: '2026-10-15', label: '15/10', in: 4, out: 6, bot: 3, staff: 2, system: 1, rule: 0 }, { key: '2026-10-16', label: '16/10', in: 0, out: 0, bot: 0, staff: 0, system: 0, rule: 0 }],
        totals: { in: 4, out: 6, bot: 3, staff: 2, system: 1, rule: 0 } },
    peak: { grid: Array.from({ length: 7 }, (_, d) => Array.from({ length: 24 }, (_, h) => (d === 1 && h === 0 ? 3 : 0))), max: 3 },
    top: [{ id: 7, name: '9627•••4567', messages: 10, inbound: 4 }],
    first_human: { handoffs: 3, answered: 2, unanswered: 1, median_s: 360, p90_s: 7200, estimated: true },
    quotes: { requests: 3, invoiced: 2, rate: 66.7, won: 1, invoiced_value_jod: 500.5, by_type: [{ type: 'flight', label: 'تذاكر', requests: 2, invoiced: 2 }] },
});
describe('AnalyticsTraffic', () => {
    it('renders bars, the peak map, bot versus staff, first human reply and quote conversion', () => {
        const w = mount(AnalyticsTraffic, { props: { traffic: traffic(), period: '7d' } });
        expect(w.find('[data-test="traffic-bars"]').text()).toContain('وارد 4');
        expect(w.find('[data-test="traffic-bars"]').text()).toContain('آخر 7 أيام');
        expect(w.findAll('[data-test="peak"] [title^="الإثنين 0:00 — 3"]')).toHaveLength(1);
        expect(w.find('[data-test="bot-staff"]').text()).toContain('3');
        const first = w.find('[data-test="first-human"]').text();
        expect(first).toContain('تقديري');
        expect(first).toContain('6 د');
        expect(first).toContain('2.0 س');
        expect(w.find('[data-test="quotes"]').text()).toContain('66.7%');
        expect(w.find('[data-test="quotes"]').text()).toContain('500.500');
        expect(w.find('[data-test="top"] a').attributes('href')).toBe('/whatsapp/inbox?conv=7');
    });
});

describe('Analytics page period switcher', () => {
    afterEach(() => vi.restoreAllMocks());

    it('reloads every tab for the chosen period', async () => {
        const { default: Analytics } = await import('@/Pages/WhatsApp/Analytics.vue');
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { v2: true, period: '24h', usage: { tiles: {}, series: [] }, quality: {}, traffic: traffic() } });
        const layout = { template: '<div><slot name="header" /><slot /></div>' };
        const w = mount(Analytics, {
            props: { usage: { tiles: {}, series: [] }, quality: {}, v2: true, period: '7d', traffic: traffic() },
            global: { stubs: { AppLayout: layout } },
        });

        expect(w.findAll('[role="tab"]').map((t) => t.text())).toContain('📈 الحركة');
        await w.find('[data-test="period-24h"]').trigger('click');
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/api/whatsapp/analytics', { params: { period: '24h' } });
        expect(w.find('[data-test="period-24h"]').attributes('aria-pressed')).toBe('true');
    });

    it('keeps the old page without the flag', async () => {
        const { default: Analytics } = await import('@/Pages/WhatsApp/Analytics.vue');
        const layout = { template: '<div><slot name="header" /><slot /></div>' };
        const w = mount(Analytics, { props: { usage: { tiles: {}, series: [] }, quality: {} }, global: { stubs: { AppLayout: layout } } });
        expect(w.find('[data-test="periods"]').exists()).toBe(false);
        expect(w.findAll('[role="tab"]')).toHaveLength(2);
    });
});
