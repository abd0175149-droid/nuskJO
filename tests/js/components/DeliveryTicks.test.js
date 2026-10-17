import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DeliveryTicks from '@/Components/WhatsApp/DeliveryTicks.vue';

const ticks = (props) => mount(DeliveryTicks, { props });
const mark = (w) => w.find('[role="img"][data-status]');

// تقريب sRGB لألوان Tailwind 4 المستعملة، والتذهيب من resources/css/app.css
const HEX = { white: '#ffffff', 'sky-700': '#0069a8', 'emerald-600': '#009966', 'gray-500': '#6a7282', 'gold-500': '#d4a017' };
const rgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
const lin = (c) => { const s = c / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; };
const lum = ([r, g, b]) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
// سطر الحالة في الفقاعة بشفافية 70%: اللون الظاهر خليط اللون وخلفية الفقاعة
const over = (fg, bg, alpha = 0.7) => rgb(fg).map((c, i) => alpha * c + (1 - alpha) * rgb(bg)[i]);

describe('DeliveryTicks', () => {
    it('shows one faint tick for sent and two for delivered, with an Arabic label on each', () => {
        const sent = ticks({ status: 'sent' });
        expect(mark(sent).text()).toBe('✓');
        expect(mark(sent).attributes('aria-label')).toBe('أُرسلت');
        expect(mark(sent).classes()).toContain('opacity-70');

        const delivered = ticks({ status: 'delivered', statusAt: '14:05' });
        expect(mark(delivered).text()).toBe('✓✓');
        expect(mark(delivered).attributes('aria-label')).toBe('سُلّمت 14:05');
        expect(mark(delivered).attributes('title')).toContain('غياب علامة القراءة لا يعني أن العميل لم يقرأ');
    });

    it('shows read and played on a blue badge with the time from Meta', () => {
        const read = ticks({ status: 'read', statusAt: '14:06' });
        expect(mark(read).text()).toBe('✓✓');
        expect(mark(read).classes()).toEqual(expect.arrayContaining(['bg-sky-700', 'text-white']));
        expect(mark(read).attributes('aria-label')).toBe('قُرئت 14:06');
        // E34: الرسالة الصوتية التي شغّلها العميل شارة «▶️ سُمعت» بدل ✓✓
        const played = mark(ticks({ status: 'played', statusAt: '14:07' }));
        expect(played.text()).toBe('▶️ سُمعت');
        expect(played.attributes('aria-label')).toBe('سُمعت 14:07');
        expect(played.attributes('dir')).toBe('rtl');
        expect(played.classes()).toEqual(expect.arrayContaining(['bg-sky-700', 'text-white']));
    });

    it('adds a small warning when one of the customer devices failed', () => {
        const w = ticks({ status: 'delivered', multiFail: true });
        const warn = w.find('[data-test="multi-fail"]');
        expect(warn.attributes('role')).toBe('img');
        expect(warn.attributes('aria-label')).toBe('فشل التسليم على أحد أجهزة العميل');
        expect(warn.attributes('title')).toBe('فشل التسليم على أحد أجهزة العميل');
        expect(ticks({ status: 'delivered' }).find('[data-test="multi-fail"]').exists()).toBe(false);
    });

    it('draws nothing for the states E19 already shows', () => {
        for (const status of ['queued', 'unknown', 'failed', 'blocked', '', 'deleted']) {
            expect(ticks({ status }).find('[data-test="delivery-ticks"]').exists(), status).toBe(false);
        }
    });

    it('keeps the read ticks readable on every bubble colour, in both themes (bubbles do not change in dark mode)', () => {
        for (const bubble of ['emerald-600', 'gray-500', 'gold-500']) {
            const badge = over(HEX['sky-700'], HEX[bubble]);
            const tick = over(HEX.white, HEX[bubble]);
            expect(contrast(tick, badge), bubble).toBeGreaterThanOrEqual(3);
        }
    });
});
