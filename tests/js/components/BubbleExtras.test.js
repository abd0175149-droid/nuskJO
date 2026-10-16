import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BubbleExtras from '@/Components/WhatsApp/BubbleExtras.vue';
import MessageBubble from '@/Components/WhatsApp/MessageBubble.vue';

const msg = (over = {}) => ({ id: 5, direction: 'in', source: 'customer', type: 'text', body: 'Hello', at: '10:00', ...over });
const mountExtras = (m, props = {}) => mount(BubbleExtras, { props: { m, conversationId: 3, canReply: true, translate: true, pins: true, ...props } });

describe('BubbleExtras (E29)', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows a stored translation as text even when it holds markup', () => {
        const w = mountExtras(msg({ translation: { text: '<img src=x onerror=alert(1)> مرحباً', src: 'en' } }));
        const box = w.find('[data-test="translation"]');
        expect(box.find('img').exists()).toBe(false);
        expect(box.text()).toContain('<img src=x onerror=alert(1)> مرحباً');
        expect(box.text()).toContain('ترجمة آلية');
        expect(box.text()).toContain('قد لا تكون دقيقة');
        expect(w.find('[data-test="translate-btn"]').exists()).toBe(false);
    });

    it('translates on demand and hands the result up', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { text: 'مرحباً', src_lang: 'en', cached: false } });
        const w = mountExtras(msg({ can_translate: true }));
        await w.find('[data-test="translate-btn"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/api/whatsapp/conversations/3/messages/5/translate', {}, expect.anything());
        expect(w.emitted('translated')[0][0]).toEqual({ text: 'مرحباً', src: 'en' });
    });

    it('explains a refusal from the server', async () => {
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 409, data: { code: 'LLM_TERMS', error: 'الترجمة معطّلة حتى يؤكّد المدير' } } });
        const w = mountExtras(msg({ can_translate: true }));
        await w.find('[data-test="translate-btn"]').trigger('click');
        await flushPromises();
        expect(w.find('[role="alert"]').text()).toBe('الترجمة معطّلة حتى يؤكّد المدير');
    });

    it('hides the button without the flag or for messages that cannot be translated', () => {
        expect(mountExtras(msg({ can_translate: true }), { translate: false, pins: false }).find('[data-test="translate-btn"]').exists()).toBe(false);
        expect(mountExtras(msg(), { pins: false }).find('[data-test="translate-btn"]').exists()).toBe(false);
    });

    it('tags a translated reply and keeps the staff text behind a toggle', () => {
        const w = mountExtras(msg({ direction: 'out', source: 'staff', translated: { to: 'en', to_label: 'الإنجليزية', source_text: 'السعر 150' } }));
        const tag = w.find('[data-test="translated-tag"]');
        expect(tag.text()).toContain('أُرسلت مترجمة إلى الإنجليزية');
        expect(tag.find('details').text()).toContain('السعر 150');
    });

    it('pins and unpins internally, never for reactions or without the reply permission', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { pinned: { note: null, messages: [{ id: 5 }] } } });
        const w = mountExtras(msg(), { translate: false });
        await w.find('[data-test="pin-btn"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/api/whatsapp/conversations/3/messages/5/pin', {}, expect.anything());
        expect(w.emitted('pinned')[0][0].messages).toEqual([{ id: 5 }]);

        const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: { pinned: { note: null, messages: [] } } });
        const pinned = mountExtras(msg(), { translate: false, pinned: true });
        expect(pinned.find('[data-test="pin-btn"]').text()).toContain('مثبّتة');
        await pinned.find('[data-test="pin-btn"]').trigger('click');
        expect(del).toHaveBeenCalledWith('/api/whatsapp/conversations/3/messages/5/pin', expect.anything());

        expect(mountExtras(msg({ type: 'reaction' }), { translate: false }).find('[data-test="pin-btn"]').exists()).toBe(false);
        expect(mountExtras(msg(), { translate: false, canReply: false }).find('[data-test="pin-btn"]').exists()).toBe(false);
    });

    it('renders inside the bubble through the extras slot', () => {
        const w = mount(MessageBubble, { props: { m: msg() }, slots: { extras: '<p data-test="slot">x</p>' } });
        expect(w.find('[data-test="slot"]').exists()).toBe(true);
    });
});
