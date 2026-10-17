import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BlockDialog from '@/Components/WhatsApp/BlockDialog.vue';
import Composer from '@/Components/WhatsApp/Composer.vue';
import ConversationList from '@/Components/WhatsApp/ConversationList.vue';
import ThreadHeader from '@/Components/WhatsApp/ThreadHeader.vue';

// الحظر الرسمي في الصندوق (E31): زر الحظر وشروطه، والحوار بسببه وتأكيده، والمنشئ المقفل، وتبويب المحظورين
const thread = (over = {}) => ({ id: 5, name: 'عميل', phone: '16505551234', window_open: true, topics: [], ...over });

describe('ThreadHeader block button', () => {
    it('shows only to block holders, never for an admin, staff or blocked thread', () => {
        expect(mount(ThreadHeader, { props: { thread: thread(), canBlock: false } }).find('[data-test="block-btn"]').exists()).toBe(false);
        expect(mount(ThreadHeader, { props: { thread: thread(), canBlock: true } }).find('[data-test="block-btn"]').exists()).toBe(true);
        for (const over of [{ is_admin: true }, { is_staff: true }, { blocked: true }]) {
            expect(mount(ThreadHeader, { props: { thread: thread(over), canBlock: true } }).find('[data-test="block-btn"]').exists()).toBe(false);
        }
    });

    it('is disabled outside the 24h window and says why', async () => {
        const w = mount(ThreadHeader, { props: { thread: thread({ window_open: false }), canBlock: true } });
        const btn = w.find('[data-test="block-btn"]');
        expect(btn.attributes('disabled')).toBeDefined();
        expect(btn.attributes('title')).toContain('24 ساعة');
        await btn.trigger('click');
        expect(w.emitted('block')).toBeUndefined();
    });

    it('grows stronger next to a rate-limit attention, and emits block', async () => {
        const w = mount(ThreadHeader, { props: { thread: thread({ needs_attention: true, attention_reason: 'rate_limit' }), canBlock: true } });
        const btn = w.find('[data-test="block-btn"]');
        expect(btn.text()).toContain('حظر الرقم');
        await btn.trigger('click');
        expect(w.emitted('block')).toHaveLength(1);
    });
});

describe('BlockDialog', () => {
    afterEach(() => vi.restoreAllMocks());

    const open = (conversation = { id: 9, name: 'عميل مزعج', phone: '16505551234' }) =>
        mount(BlockDialog, { props: { open: true, conversation }, attachTo: document.body });

    it('needs a reason, 10+ characters of details and a confirmation before it posts', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true, block_id: 3 } });
        const w = open();
        expect(w.text()).toContain('…1234');
        expect(w.text()).toContain('اكتب آخر 4 أرقام للتأكيد');
        const submit = w.find('button[type="submit"]');
        expect(submit.attributes('disabled')).toBeDefined();

        await w.find('input[value="abuse"]').setValue(true);
        await w.find('textarea').setValue('قصير');
        await w.find('input[inputmode="numeric"]').setValue('1234');
        expect(submit.attributes('disabled')).toBeDefined();

        await w.find('textarea').setValue('شتائم متكررة للموظفين');
        expect(submit.attributes('disabled')).toBeUndefined();
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/conversations/9/block',
            { reason_code: 'abuse', reason: 'شتائم متكررة للموظفين', confirm: '1234' }, expect.anything());
        expect(w.emitted('blocked')[0]).toEqual([{ ok: true, block_id: 3 }]);
        w.unmount();
    });

    it('asks for the word «حظر» when the number is hidden, and shows the server refusal', async () => {
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 422, data: { error: 'ميتا لا تسمح بالحظر إلا خلال 24 ساعة.' } } });
        const w = open({ id: 4, name: 'بلا رقم', phone: null });
        expect(w.text()).toContain('📵 رقم غير ظاهر');
        expect(w.text()).toContain('اكتب كلمة «حظر» للتأكيد');

        await w.find('input[value="spam"]').setValue(true);
        await w.find('textarea').setValue('رسائل مزعجة كل دقيقة');
        await w.find('input[maxlength="20"]').setValue('حظر');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(w.find('[role="alert"]').text()).toContain('24 ساعة');
        expect(w.emitted('blocked')).toBeUndefined();
        w.unmount();
    });
});

describe('Composer while blocked', () => {
    it('explains the block and refuses to send', async () => {
        const w = mount(Composer, { props: { modelValue: 'مرحبا', canReply: true, windowOpen: true, blocked: true, finePointer: true } });
        expect(w.find('[data-test="blocked-note"]').text()).toContain('محظور');
        expect(w.find('textarea').attributes('disabled')).toBeDefined();
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')).toBeUndefined();
    });
});

describe('ConversationList blocked tab', () => {
    const row = (id, over = {}) => ({ id, name: `عميل ${id}`, phone: '16505551234', unread: 0, last_message: 'مرحبا', ...over });

    it('offers «المحظورون» to block holders only, and badges a blocked row', async () => {
        const without = mount(ConversationList, { props: { items: [row(1)], seesAll: true } });
        expect(without.text()).not.toContain('المحظورون');

        const w = mount(ConversationList, { props: { items: [row(1, { blocked: true })], seesAll: true, canBlock: true } });
        expect(w.text()).toContain('⛔ محظور');
        const tab = w.findAll('button').find((b) => b.text().includes('المحظورون'));
        await tab.trigger('click');
        expect(w.emitted('update:filter')[0]).toEqual(['blocked']);
    });
});
