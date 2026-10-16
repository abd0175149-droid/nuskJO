import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PendingBubble from '@/Components/WhatsApp/PendingBubble.vue';

// فقاعة الملف المتفائلة (E32.10): الرفع بنسبته، ثم «في الطابور»، وبعد انقطاع الشبكة إعادة المحاولة أو الإلغاء
const item = (over = {}) => ({ key: 'k', name: 'visa.pdf', size: 2048, kind: 'document', caption: '', state: 'uploading', progress: 40, error: '', warning: '', preview: null, ...over });

describe('PendingBubble', () => {
    it('shows the upload progress', () => {
        const w = mount(PendingBubble, { props: { item: item() } });
        expect(w.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('40');
        expect(w.find('[data-test="state-uploading"]').text()).toContain('40%');
        expect(w.text()).toContain('visa.pdf');
    });

    it('says queued once the server accepted the file', () => {
        const w = mount(PendingBubble, { props: { item: item({ state: 'queued', caption: 'تأشيرتك', warning: 'تنبيه' }) } });
        expect(w.find('[data-test="state-queued"]').text()).toContain('في الطابور');
        expect(w.text()).toContain('تأشيرتك');
        expect(w.text()).toContain('تنبيه');
        expect(w.find('[role="progressbar"]').exists()).toBe(false);
    });

    it('offers retry and cancel after a network failure', async () => {
        const it0 = item({ state: 'network', error: 'انقطع الاتصال' });
        const w = mount(PendingBubble, { props: { item: it0 } });
        expect(w.find('[role="alert"]').text()).toContain('انقطع الاتصال');
        await w.find('[data-test="outbox-retry"]').trigger('click');
        expect(w.emitted('retry')[0][0]).toStrictEqual(it0);
        await w.findAll('button').find((b) => b.text() === 'إلغاء').trigger('click');
        expect(w.emitted('dismiss')).toHaveLength(1);
    });

    it('previews a photo without loading anything from the server', () => {
        const w = mount(PendingBubble, { props: { item: item({ kind: 'image', preview: 'blob:local' }) } });
        expect(w.find('img').attributes('src')).toBe('blob:local');
    });
});
