import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ExportDialog from '@/Components/WhatsApp/ExportDialog.vue';
import { errorBody, fileNameFrom } from '@/composables/whatsapp/useDownload.js';

// نافذة التصدير (E30.8): سبب إلزامي وتنبيه بيانات شخصية، والتنزيل بمعاملاته، والأخطاء داخل النافذة
const mountDialog = (downloader) => mount(ExportDialog, { props: { open: true, conversation: { id: 12 }, downloader }, attachTo: document.body });

describe('ExportDialog', () => {
    it('needs a reason of five letters and warns about personal data', async () => {
        const downloader = vi.fn().mockResolvedValue('x');
        const w = mountDialog(downloader);

        expect(w.text()).toContain('الملف يحوي بيانات شخصية للعميل');
        const submit = w.find('[data-test="export-submit"]');
        expect(submit.attributes('disabled')).toBeDefined();
        await w.find('textarea').setValue('نزاع');
        expect(submit.attributes('disabled')).toBeDefined();
        await w.find('textarea').setValue('نزاع دفع');
        expect(submit.attributes('disabled')).toBeUndefined();
        w.unmount();
    });

    it('downloads with the chosen format, period and reason, then closes', async () => {
        const downloader = vi.fn().mockResolvedValue('wa-conv-12.csv');
        const w = mountDialog(downloader);
        await w.find('input[value="csv"]').setValue(true);
        const dates = w.findAll('input[type="date"]');
        await dates[0].setValue('2026-10-01');
        await dates[1].setValue('2026-10-15');
        await w.find('textarea').setValue('  طلب اطّلاع من العميل  ');
        await w.find('form').trigger('submit');
        await vi.waitFor(() => expect(w.emitted('close')).toBeTruthy());

        expect(downloader).toHaveBeenCalledWith('/api/whatsapp/conversations/12/export',
            { format: 'csv', from: '2026-10-01', to: '2026-10-15', reason: 'طلب اطّلاع من العميل' }, { fallbackName: 'wa-conv-12.csv' });
        w.unmount();
    });

    it('shows the server reason from a blob error, and throttling in Arabic', async () => {
        const blob = new Blob([JSON.stringify({ message: 'المحادثة أطول من 20,000 رسالة في هذه الفترة: ضيّق الفترة.', rows: 25000 })], { type: 'application/json' });
        const downloader = vi.fn().mockRejectedValueOnce({ response: { status: 422, data: blob } }).mockRejectedValueOnce({ response: { status: 429 } });
        const w = mountDialog(downloader);
        await w.find('textarea').setValue('نزاع على الدفع');

        await w.find('form').trigger('submit');
        await vi.waitFor(() => expect(w.find('[role="alert"]').exists()).toBe(true));
        expect(w.find('[role="alert"]').text()).toContain('ضيّق الفترة');

        await w.find('form').trigger('submit');
        await vi.waitFor(() => expect(w.find('[role="alert"]').text()).toContain('انتظر قليلاً'));
        expect(w.emitted('close')).toBeUndefined();
        w.unmount();
    });
});

describe('download helpers', () => {
    it('reads the file name from Content-Disposition', () => {
        expect(fileNameFrom('attachment; filename=wa-conv-12-20261016-101500.txt', 'x')).toBe('wa-conv-12-20261016-101500.txt');
        expect(fileNameFrom('attachment; filename="a b.csv"', 'x')).toBe('a b.csv');
        expect(fileNameFrom("attachment; filename*=UTF-8''%D9%85.txt", 'x')).toBe('م.txt');
        expect(fileNameFrom(undefined, 'fallback.txt')).toBe('fallback.txt');
    });

    it('parses a JSON error body that arrived as a blob', async () => {
        expect(await errorBody({ response: { data: new Blob(['{"message":"x"}']) } })).toEqual({ message: 'x' });
        expect(await errorBody({ response: { data: new Blob(['not json']) } })).toEqual({});
        expect(await errorBody({})).toEqual({});
    });
});
