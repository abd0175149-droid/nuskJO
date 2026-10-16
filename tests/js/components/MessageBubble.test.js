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

    it('shows the Arabic reason, a coloured category and the action behind ⓘ (E18)', async () => {
        const w = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff', sender: 'سارة', status: 'failed',
            error: 'رمز ميتا منتهٍ أو مُلغى — الإرسال متوقف.', category: 'auth', action: 'ولّد رمزاً جديداً', fbtrace_id: 'Ax1' }) } });
        expect(w.text()).toContain('رمز ميتا منتهٍ');
        const chip = w.find('[data-test="error-category"]');
        expect(chip.text()).toBe('اعتماد');
        expect(chip.classes()).toContain('bg-red-100');
        const action = w.find('[data-test="error-action"]');
        expect(action.element.tagName).toBe('DETAILS');
        expect(action.text()).toContain('ماذا أفعل؟');
        expect(action.text()).toContain('ولّد رمزاً جديداً');
        expect(action.text()).toContain('Ax1');
    });

    it('colours the window amber and the request grey, and shows unconfirmed reasons too', () => {
        const window = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'bot', status: 'failed', error: 'انتهت النافذة', category: 'window' }) } });
        expect(window.find('[data-test="error-category"]').classes()).toContain('bg-amber-100');
        const caller = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'bot', status: 'failed', error: 'تعذّر التسليم', category: 'caller' }) } });
        expect(caller.find('[data-test="error-category"]').classes()).toContain('bg-gray-100');
        const timeout = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff', status: 'unknown', error: 'انتهت مهلة ميتا — قد تكون الرسالة وصلت.' }) } });
        expect(timeout.text()).toContain('قد تكون الرسالة وصلت');
        expect(timeout.find('[data-test="error-action"]').exists()).toBe(false);
    });

    it('renders a reason carrying markup as text', () => {
        const w = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'bot', status: 'failed', error: '<img src=x onerror=alert(1)>', category: 'unknown', action: '<b>x</b>' }) } });
        expect(w.find('img').exists()).toBe(false);
        expect(w.find('b').exists()).toBe(false);
        expect(w.text()).toContain('<img');
    });
});
