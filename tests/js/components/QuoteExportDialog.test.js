import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import QuoteExportDialog from '@/Components/Quotes/QuoteExportDialog.vue';
import { defaultRange, exportUrl, rangeError } from '@/utils/quoteExport.js';

// تصدير طلبات التسعير (E24): مدى سنة على الأكثر، والرابط بالفلاتر عند الطلب فقط
describe('quote export helpers', () => {
    it('defaults to the start of the month until today', () => {
        expect(defaultRange(new Date(2026, 9, 16))).toEqual({ from: '2026-10-01', to: '2026-10-16' });
    });

    it('refuses a reversed or over-a-year range', () => {
        expect(rangeError('2026-10-01', '2026-10-16')).toBe('');
        expect(rangeError('2026-10-16', '2026-10-01')).toContain('قبل');
        expect(rangeError('2025-01-01', '2026-01-05')).toContain('سنة');
        expect(rangeError('', '2026-01-05')).toContain('اختر');
    });

    it('builds the export url with optional filters', () => {
        expect(exportUrl({ from: '2026-10-01', to: '2026-10-16', status: 'all', search: null })).toBe('/quote-requests/export?from=2026-10-01&to=2026-10-16&status=all');
        expect(exportUrl({ from: '2026-10-01', to: '2026-10-16', status: 'won', search: 'QR-1' })).toBe('/quote-requests/export?from=2026-10-01&to=2026-10-16&status=won&search=QR-1');
    });
});

describe('QuoteExportDialog', () => {
    afterEach(() => vi.restoreAllMocks());
    const make = (props = {}) => mount(QuoteExportDialog, { props: { open: true, status: 'won', search: 'QR', ...props }, global: { stubs: { AppModal: { template: '<div><slot /><slot name="footer" /></div>' } } } });

    it('navigates to all requests in range by default, and to the page filter when asked', async () => {
        const assign = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign });
        const w = make();
        await w.find('[data-test="from"]').setValue('2026-09-01');
        await w.find('[data-test="to"]').setValue('2026-09-30');
        await w.find('form').trigger('submit');
        expect(w.emitted('navigate')[0][0]).toBe('/quote-requests/export?from=2026-09-01&to=2026-09-30&status=all');

        await w.find('[data-test="only-filter"]').setValue(true);
        await w.find('form').trigger('submit');
        expect(w.emitted('navigate')[1][0]).toBe('/quote-requests/export?from=2026-09-01&to=2026-09-30&status=won&search=QR');
        vi.unstubAllGlobals();
    });

    it('blocks an invalid range with an Arabic message', async () => {
        const w = make();
        await w.find('[data-test="from"]').setValue('2026-10-10');
        await w.find('[data-test="to"]').setValue('2026-10-01');
        await w.find('form').trigger('submit');
        expect(w.find('[data-test="error"]').text()).toContain('قبل');
        expect(w.emitted('navigate')).toBeUndefined();
    });
});
