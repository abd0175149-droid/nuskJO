import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import TranslateReplyPanel from '@/Components/WhatsApp/TranslateReplyPanel.vue';

const SOURCE = 'السعر ١٥٠ دينار، والسفر في ٢٠/١١';
const props = { open: false, conversationId: 7, sourceText: SOURCE, target: 'en', targets: { en: 'الإنجليزية' } };

async function opened(translation = 'Price 150 JOD, travel on 20/11') {
    vi.spyOn(axios, 'post').mockImplementation(async (url) => {
        if (url.endsWith('/translate-reply')) return { data: { translation, target: 'en', check_token: 'tok', numbers: {} } };
        return { data: { ok: true } };
    });
    const w = mount(TranslateReplyPanel, { props });
    await w.setProps({ open: true });
    await flushPromises();
    return w;
}

describe('TranslateReplyPanel (E29)', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows the Arabic text read-only and an editable translation with matching numbers', async () => {
        const w = await opened();
        expect(w.find('[data-test="source"]').text()).toBe(SOURCE);
        expect(w.find('[data-test="translation"]').element.value).toBe('Price 150 JOD, travel on 20/11');
        expect(w.find('[data-test="numbers"]').text()).toContain('الأرقام متطابقة');
        expect(w.find('[data-test="send"]').element.disabled).toBe(false);
    });

    it('re-checks the numbers on every edit and blocks a mismatch or a spelled number', async () => {
        const w = await opened();
        await w.find('[data-test="translation"]').setValue('Price 105 JOD, travel on 20/11');
        expect(w.find('[data-test="send"]').element.disabled).toBe(true);
        expect(w.find('[data-test="numbers"]').text()).toContain('لا تطابق');
        await w.find('[data-test="translation"]').setValue('Price one hundred fifty JOD, travel on 20/11');
        expect(w.find('[data-test="spelled"]').text()).toContain('one، hundred، fifty');
        expect(w.find('[data-test="send"]').element.disabled).toBe(true);
    });

    it('sends the edited text with the token, the source text and an Idempotency-Key', async () => {
        const w = await opened();
        await w.find('[data-test="translation"]').setValue('Dear guest, 150 JOD, travel on 20/11');
        await w.find('[data-test="send"]').trigger('click');
        await flushPromises();
        const [url, body, config] = axios.post.mock.calls.at(-1);
        expect(url).toBe('/api/whatsapp/conversations/7/send-translated');
        expect(body).toEqual({ body: 'Dear guest, 150 JOD, travel on 20/11', translation: { source_text: SOURCE, target: 'en', check_token: 'tok' } });
        expect(config.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
        expect(w.emitted('sent')[0][0]).toEqual({ unknown: false, message: '' });
    });

    it('shows what the server refused', async () => {
        const w = await opened();
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { code: 'NUMBERS_MISMATCH', error: 'أرقام الترجمة لا تطابق أرقام النص الأصلي', missing: ['150'], extra: ['105'] } } });
        await w.find('[data-test="send"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-test="error"]').text()).toContain('الناقص 150، والزائد 105');
        expect(w.emitted('sent')).toBeUndefined();
    });
});
