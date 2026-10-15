import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MessageBubble from '@/Components/WhatsApp/MessageBubble.vue';

const msg = (over = {}) => ({ id: 1, direction: 'in', source: 'customer', body: '', at: '10:00', status: null, ...over });

describe('MessageBubble', () => {
    it('renders customer text as text, with formatting and safe links', () => {
        const w = mount(MessageBubble, { props: { m: msg({ body: '<b>x</b> _مهم_ https://nusk.example/p' }) } });
        expect(w.find('b').exists()).toBe(false);
        expect(w.text()).toContain('<b>x</b>');
        expect(w.find('em').text()).toBe('مهم');
        expect(w.find('a').attributes('rel')).toBe('noopener noreferrer nofollow');
        expect(w.find('p[dir="auto"]').exists()).toBe(true);
    });

    it('shows a blocked outgoing message in red with its reason', () => {
        const w = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'bot', status: 'blocked', error: 'POLICY_X', body: 'نص' }) } });
        expect(w.text()).toContain('حُجبت');
        expect(w.text()).toContain('POLICY_X');
        expect(w.html()).toContain('bg-red-50');
    });

    it('renders a system identity update as a centred strip, not a bubble', () => {
        const w = mount(MessageBubble, { props: { m: msg({ is_system: true, body: 'تحديث الهوية' }) } });
        expect(w.find('.rounded-full').text()).toContain('تحديث الهوية');
        expect(w.find('.rounded-2xl').exists()).toBe(false);
    });

    it('labels the source of outgoing messages without a named sender', () => {
        expect(mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'bot' }) } }).text()).toContain('بوت');
        const staff = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff', sender: 'سارة' }) } });
        expect(staff.text()).toContain('👤 سارة');
        expect(staff.text()).not.toContain('· موظف');
    });
});
