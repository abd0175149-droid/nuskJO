import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { readdirSync, readFileSync } from 'node:fs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ComposerExtras from '@/Components/WhatsApp/ComposerExtras.vue';
import CorrectionDialog from '@/Components/WhatsApp/CorrectionDialog.vue';
import MessageBubble from '@/Components/WhatsApp/MessageBubble.vue';
import MessageRich from '@/Components/WhatsApp/MessageRich.vue';
import QuoteBlock from '@/Components/WhatsApp/QuoteBlock.vue';
import ShareContactDialog from '@/Components/WhatsApp/ShareContactDialog.vue';
import ThreadView from '@/Components/WhatsApp/ThreadView.vue';

const msg = (over = {}) => ({ id: 5, direction: 'in', source: 'customer', type: 'text', body: 'هذا الجواز', at: '10:00', ...over });

describe('MessageBubble actions (E33)', () => {
    afterEach(() => vi.useRealTimers());

    it('hides the actions without reply permission', () => {
        const w = mount(MessageBubble, { props: { m: msg({ can_quote: true }) } });
        expect(w.find('[data-test="message-actions"]').exists()).toBe(false);
        expect(w.find('[data-actionable]').exists()).toBe(false);
        const on = mount(MessageBubble, { props: { m: msg({ can_quote: true }), actionable: true } });
        expect(on.find('[data-test="message-actions"]').exists()).toBe(true);
    });

    it('opens the menu with ⋯ and with a 500ms long press', async () => {
        vi.useFakeTimers();
        const w = mount(MessageBubble, { props: { m: msg(), actionable: true } });
        await w.find('[data-test="message-actions"]').trigger('click');
        expect(w.emitted('actions')).toHaveLength(1);
        const bubble = w.find('[data-actionable]');
        bubble.element.dispatchEvent(Object.assign(new Event('pointerdown'), { pointerType: 'touch', button: 0, clientX: 5, clientY: 5 }));
        vi.advanceTimersByTime(500);
        expect(w.emitted('actions')).toHaveLength(2);
        expect(w.emitted('actions')[1][0].id).toBe(5);
    });

    it('paints an unsupported message amber', () => {
        const w = mount(MessageBubble, { props: { m: msg({ type: 'unsupported', body: '📊 أرسل العميل استطلاعاً لا تعرضه منصة ميتا' }) } });
        expect(w.html()).toContain('bg-amber-50');
    });
});

describe('QuoteBlock and MessageRich (E33)', () => {
    it('jumps to the quoted message and notes a dropped quote', async () => {
        const w = mount(QuoteBlock, { props: { quote: { id: 9, snippet: '<b>عرض</b>', direction: 'out' }, dropped: true } });
        expect(w.find('b').exists()).toBe(false);
        expect(w.text()).toContain('<b>عرض</b>');
        await w.find('[data-test="quote-block"]').trigger('click');
        expect(w.emitted('jump')[0]).toEqual([9]);
        expect(w.find('[data-test="quote-dropped"]').exists()).toBe(true);
    });

    it('shows a location with an external maps link and no embedded map', () => {
        const w = mount(MessageRich, { props: { m: msg({ type: 'location', location: { lat: 31.95, lng: 35.91, name: 'المكتب', address: 'عمّان', maps_url: 'https://www.google.com/maps/search/?api=1&query=31.95,35.91' } }) } });
        const a = w.find('[data-test="maps-link"]');
        expect(a.attributes('target')).toBe('_blank');
        expect(a.attributes('rel')).toBe('noopener noreferrer nofollow');
        expect(w.find('iframe').exists()).toBe(false);
        expect(w.find('script').exists()).toBe(false);
    });

    it('shows card names, numbers only when the server sent them, reactions and the correction badge', async () => {
        const w = mount(MessageRich, { props: { m: msg({ type: 'contacts', contact_cards: [{ name: 'سائق' }, { name: 'مندوب', phones: ['+1 650-555-3333'] }],
            reactions: { customer: '🙏', business: '✅' }, corrected_by: [12] }) } });
        expect(w.find('[data-test="contact-cards"]').text()).toContain('سائق');
        expect(w.findAll('bdi')).toHaveLength(1);
        expect(w.find('[data-test="reaction-customer"]').text()).toBe('🙏');
        expect(w.find('[data-test="reaction-business"]').text()).toContain('الموظف');
        expect(w.find('[data-test="corrected-badge"]').text()).toContain('أُرسل تصحيح ↓');
        await w.find('[data-test="corrected-badge"] button').trigger('click');
        expect(w.emitted('jump')[0]).toEqual([12]);
    });
});

describe('ComposerExtras (E33)', () => {
    const base = { conversationId: 3, canReply: true, windowOpen: true, features: { quote: true, preview: true, share: true } };

    it('shows the reply bar and cancels it with ✕ or Esc', async () => {
        const w = mount(ComposerExtras, { props: { ...base, replyTo: { id: 9, snippet: 'كم السعر؟', direction: 'in' } }, attachTo: document.body });
        expect(w.find('[data-test="reply-bar"]').text()).toContain('ترد على: كم السعر؟');
        expect(w.text()).toContain('قد لا تظهر فقاعة الاقتباس عند العميل لرسالة أقدم من نحو 30 يوماً');
        await w.find('[data-test="cancel-reply"]').trigger('click');
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('cancelReply')).toHaveLength(2);
        w.unmount();
    });

    it('offers link preview only with a link and the flag, and share buttons only with approved items', () => {
        expect(mount(ComposerExtras, { props: { ...base, draft: 'بلا رابط' } }).find('[data-test="preview-toggle"]').exists()).toBe(false);
        expect(mount(ComposerExtras, { props: { ...base, draft: 'https://www.nusuk.sa' } }).find('[data-test="preview-toggle"]').exists()).toBe(true);
        expect(mount(ComposerExtras, { props: { ...base, draft: 'https://x.org', features: { preview: false } } }).find('[data-test="preview-toggle"]').exists()).toBe(false);
        const share = mount(ComposerExtras, { props: { ...base, shareItems: { places: [{ id: 1, name: 'المكتب' }], cards: [] } } });
        expect(share.find('[data-test="share-location"]').exists()).toBe(true);
        expect(share.find('[data-test="share-contact"]').exists()).toBe(false);
    });
});

describe('CorrectionDialog (E33)', () => {
    afterEach(() => vi.restoreAllMocks());

    it('starts with «تصحيح: », warns that the original stays, and asks before a policy warning', async () => {
        const calls = [];
        const sendFn = vi.fn(async (...args) => {
            calls.push(args);
            return calls.length === 1 ? { ok: false, status: 409, data: { code: 'POLICY_WARN', error: 'تحذير' } } : { ok: true, data: { ok: true } };
        });
        const confirmFn = vi.fn(async () => true);
        const w = mount(CorrectionDialog, { props: { open: false, message: msg({ id: 7, direction: 'out', body: 'السعر 150' }), conversationId: 3, sendFn, confirmFn } });
        await w.setProps({ open: true });
        expect(w.find('textarea').element.value).toBe('تصحيح: ');
        expect(w.find('[data-test="correction-warning"]').text()).toContain('لن يتغيّر نص الرسالة الأصلية عند العميل');
        expect(w.find('[data-test="send-correction"]').attributes('disabled')).toBeDefined();
        await w.find('textarea').setValue('تصحيح: السعر 165');
        await w.find('[data-test="send-correction"]').trigger('click');
        await flushPromises();
        expect(confirmFn).toHaveBeenCalledWith('تحذير');
        expect(calls[1]).toEqual([3, 7, 'تصحيح: السعر 165', true]);
        expect(w.emitted('sent')).toHaveLength(1);
    });
});

describe('ShareContactDialog (E33)', () => {
    it('allows three cards at most', async () => {
        const cards = [1, 2, 3, 4].map((id) => ({ id, label: `بطاقة ${id}`, name: 'اسم' }));
        const w = mount(ShareContactDialog, { props: { open: false, cards, conversationId: 3, recipient: { name: 'عميل' } } });
        await w.setProps({ open: true });
        const boxes = w.findAll('[data-test="card-option"]');
        for (const b of boxes.slice(0, 3)) await b.setValue(true);
        expect(boxes[3].attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="recipient-line"]').text()).toContain('عميل');
    });
});

describe('ThreadView rich thread (E33)', () => {
    afterEach(() => vi.restoreAllMocks());
    const stubs = { ThreadHeader: true, Composer: true, MediaChip: true };
    const thread = { id: 3, window_open: true, topics: [], features: { quote: true, react: true, preview: false, share: false } };

    it('hides a polled reaction whose target is loaded and shows its badge on the target', () => {
        const messages = [
            msg({ id: 10, reactions: { customer: '🙏' } }),
            { id: 11, direction: 'out', source: 'staff', type: 'reaction', body: '✅', at: '10:01', target_id: 10, target_reactions: { customer: '🙏', business: '✅' } },
        ];
        const w = mount(ThreadView, { props: { thread, messages, canReply: true }, global: { stubs } });
        expect(w.findAll('[data-msg-id]').map((e) => e.attributes('data-msg-id'))).toEqual(['10']);
        expect(w.find('[data-test="reaction-business"]').exists()).toBe(true);
    });

    it('a reply chosen from the menu travels with the next send by our id', async () => {
        const messages = [msg({ id: 10, can_quote: true, can_react: true })];
        const w = mount(ThreadView, { props: { thread, messages, canReply: true, draft: 'نعم متاحة', staffReactions: ['✅'] }, global: { stubs } });
        await w.find('[data-test="message-actions"]').trigger('click');
        await w.find('[data-test="action-reply"]').trigger('click');
        expect(w.find('[data-test="reply-bar"]').text()).toContain('هذا الجواز');
        w.findComponent({ name: 'Composer' }).vm.$emit('submit');
        const [extra] = w.emitted('send')[0];
        expect(extra.reply_to_id).toBe(10);
        extra.onSent({ ok: true });
        await w.vm.$nextTick();
        expect(w.find('[data-test="reply-bar"]').exists()).toBe(false);
    });

    it('a reaction from the menu posts our message id and asks for a refresh', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true } });
        const messages = [msg({ id: 10, can_react: true })];
        const w = mount(ThreadView, { props: { thread, messages, canReply: true, staffReactions: ['✅', '🙏'] }, global: { stubs } });
        await w.find('[data-test="message-actions"]').trigger('click');
        await w.findAll('[data-test="reaction-option"]')[0].trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/api/whatsapp/conversations/3/messages/10/react', { emoji: '✅' }, expect.anything());
        expect(w.emitted('richSent')).toHaveLength(1);
    });
});

describe('E33 components', () => {
    it('never say «عُدّلت» and never render raw HTML', () => {
        const dir = 'resources/js/Components/WhatsApp';
        const mine = ['QuoteBlock', 'ReactionPicker', 'MessageRich', 'MessageActionMenu', 'CorrectionDialog', 'ShareLocationDialog', 'ShareContactDialog', 'ComposerExtras'];
        expect(readdirSync(dir).filter((f) => mine.includes(f.replace('.vue', '')))).toHaveLength(mine.length);
        for (const name of mine) {
            const src = readFileSync(`${dir}/${name}.vue`, 'utf8');
            expect(src, name).not.toContain('عُدّلت');
            expect(src, name).not.toMatch(/\bv-html\s*=/);
        }
    });
});
