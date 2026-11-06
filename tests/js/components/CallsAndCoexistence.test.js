import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CallFromAgencyPhone from '@/Components/WhatsApp/CallFromAgencyPhone.vue';
import ConversationList from '@/Components/WhatsApp/ConversationList.vue';
import MessageBubble from '@/Components/WhatsApp/MessageBubble.vue';

// المكالمات ورسائل الهاتف: فقاعة المكالمة، ووسم هاتف الرقم والتعديل والحذف، والفلتر، وزر المعاودة
const msg = (over = {}) => ({ id: 1, direction: 'in', source: 'customer', body: '', at: '10:00', status: null, ...over });

describe('MessageBubble calls and phone echoes', () => {
    it('shows a missed call as a light red centred strip and a completed call in grey', () => {
        const missed = mount(MessageBubble, { props: { m: msg({ is_system: true, type: 'call', body: '📞 مكالمة واردة فائتة 10:42', call: { missed: true } }) } });
        const pill = missed.find('[data-test="call-pill"]');
        expect(pill.text()).toContain('فائتة');
        expect(pill.classes()).toContain('bg-red-50');
        expect(missed.find('.rounded-2xl').exists()).toBe(false);

        const done = mount(MessageBubble, { props: { m: msg({ is_system: true, type: 'call', body: '📞 مكالمة واردة، مدتها 3:12', call: { missed: false } }) } });
        expect(done.find('[data-test="call-pill"]').classes()).toContain('bg-gray-200');
    });

    it('labels a phone echo with the holder and marks edits and deletions without hiding the text', () => {
        const w = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff_phone', sender: '📱 من هاتف الوكالة · سامي', body: 'الأصل', phone: { edited: true, revoked: true } }) } });
        expect(w.text()).toContain('📱 من هاتف الوكالة · سامي');
        expect(w.text()).not.toContain('👤 📱');
        expect(w.text()).toContain('الأصل');
        expect(w.find('[data-test="phone-edited"]').exists()).toBe(true);
        expect(w.find('[data-test="phone-revoked"]').text()).toContain('حُذفت من الهاتف');
        expect(w.html()).toContain('bg-gold-500');
    });

    it('introduces an edit row as the new text from the phone', () => {
        const w = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff_phone', type: 'edit', body: 'النص الجديد', reply_to: 'الأصل' }) } });
        expect(w.find('[data-test="phone-edit"]').text()).toContain('عُدّلت من الهاتف');
        expect(w.text()).toContain('📱 هاتف الوكالة');
    });
});

describe('ConversationList missed calls filter', () => {
    it('offers the filter only with the calls flag', async () => {
        const off = mount(ConversationList, { props: { items: [] } });
        expect(off.text()).not.toContain('مكالمات فائتة');

        const on = mount(ConversationList, { props: { items: [], missedCalls: true } });
        const btn = on.findAll('button').find((b) => b.text().includes('📞 مكالمات فائتة'));
        await btn.trigger('click');
        expect(on.emitted('update:filter')[0]).toEqual(['missed_call']);
    });
});

describe('CallFromAgencyPhone', () => {
    afterEach(() => vi.restoreAllMocks());

    it('reveals the customer number for copying only', async () => {
        const write = vi.fn().mockResolvedValue();
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: write }, configurable: true });
        const w = mount(CallFromAgencyPhone, { props: { phone: '16505551234' } });
        expect(w.find('[data-test="call-number"]').exists()).toBe(false);

        await w.find('[data-test="call-from-agency"]').trigger('click');
        expect(w.find('[data-test="call-number"]').text()).toContain('+1 650 555 1234');
        expect(w.find('a[href^="tel:"]').exists()).toBe(false);

        await w.find('[data-test="copy-number"]').trigger('click');
        await flushPromises();
        expect(write).toHaveBeenCalledWith('+16505551234');
        expect(w.text()).toContain('✅ نُسخ');
    });
});
