import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { renderTemplate, loadPdfJs, PDF_OPTIONS, resetPdfJsForTests } from '@/composables/usePdfTemplate';

// pdf.js مزيّف: نتحقق من خيارات المستند (بلا eval) ومن مصدر العامل، بلا رسم حقيقي في jsdom
const pdf = vi.hoisted(() => ({ getDocument: vi.fn(), GlobalWorkerOptions: {} }));
vi.mock('pdfjs-dist/legacy/build/pdf.mjs', () => pdf);
vi.mock('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url', () => ({ default: '/build/assets/pdf.worker.min-abc123.mjs' }));

const fakeDocument = () => {
    const page = {
        getViewport: vi.fn(({ scale }) => ({ width: 595 * scale, height: 842 * scale })),
        render: vi.fn(() => ({ promise: Promise.resolve() })),
    };
    const doc = { getPage: vi.fn(async () => page), destroy: vi.fn() };
    pdf.getDocument.mockReturnValue({ promise: Promise.resolve(doc) });
    return { doc, page };
};

const canvas = () => ({ width: 0, height: 0, getContext: vi.fn(() => ({})) });

describe('usePdfTemplate', () => {
    beforeEach(() => {
        pdf.getDocument.mockReset();
        pdf.GlobalWorkerOptions.workerSrc = undefined;
        resetPdfJsForTests();
    });

    it('opens the template without eval and with the bundled worker', async () => {
        fakeDocument();

        expect(await renderTemplate('/storage/templates/invoice.pdf', [canvas()])).toBe(true);

        expect(pdf.getDocument).toHaveBeenCalledWith({ url: '/storage/templates/invoice.pdf', isEvalSupported: false, enableXfa: false });
        expect(PDF_OPTIONS.isEvalSupported).toBe(false);
        expect(pdf.GlobalWorkerOptions.workerSrc).toMatch(/^\/build\/assets\/pdf\.worker/);
    });

    it('paints the first page on every copy at scale 2 and releases the document', async () => {
        const { doc, page } = fakeDocument();
        const copies = { 0: canvas(), 1: canvas() };

        await renderTemplate('/t.pdf', copies);

        expect(doc.getPage).toHaveBeenCalledWith(1);
        expect(page.render).toHaveBeenCalledTimes(2);
        expect(copies[0].width).toBe(1190);
        expect(copies[1].height).toBe(1684);
        expect(page.render.mock.calls[0][0].canvas).toBe(copies[0]);
        expect(doc.destroy).toHaveBeenCalledOnce();
    });

    it('accepts a scale computed from the page width', async () => {
        const { page } = fakeDocument();
        const scale = vi.fn((width) => 794 / width);
        const target = canvas();

        await renderTemplate('/t.pdf', [target], { scale });

        expect(scale).toHaveBeenCalledWith(595);
        expect(page.getViewport).toHaveBeenLastCalledWith({ scale: 794 / 595 });
        expect(target.width).toBe(794);
    });

    it('does nothing without a template or a canvas', async () => {
        expect(await renderTemplate('', [canvas()])).toBe(false);
        expect(await renderTemplate('/t.pdf', [null])).toBe(false);
        expect(await renderTemplate('/t.pdf', {})).toBe(false);
        expect(pdf.getDocument).not.toHaveBeenCalled();
    });

    it('loads pdf.js once and releases the document when painting fails', async () => {
        const { doc, page } = fakeDocument();
        page.render.mockReturnValue({ promise: Promise.reject(new Error('broken')) });

        await expect(renderTemplate('/t.pdf', [canvas()])).rejects.toThrow('broken');
        expect(doc.destroy).toHaveBeenCalledOnce();
        expect(await loadPdfJs()).toBe(await loadPdfJs());
    });

    it('every print page uses the shared loader, not a script tag', () => {
        const pages = [
            'Invoices/Print', 'Receipts/Print', 'Statements/Print', 'Agents/PrintStatement', 'Clients/PrintStatement',
            'Accounting/PrintChart', 'Accounting/PrintTrialBalance', 'Accounting/PrintStatement', 'Accounting/PrintProfitLoss',
            'Accounting/PrintBalanceSheet', 'Settings/PrintLayout',
        ];
        for (const name of pages) {
            const src = readFileSync(`resources/js/Pages/${name}.vue`, 'utf8');
            expect(src, name).toContain("from '@/composables/usePdfTemplate'");
            expect(src, name).not.toMatch(/createElement\(\s*['"]script['"]\s*\)|window\.pdfjsLib|getDocument\(/);
        }
    });
});
