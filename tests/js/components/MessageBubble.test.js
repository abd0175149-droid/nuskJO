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

    it('shows queued and unknown ledger states (E19)', () => {
        const queued = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff', status: 'queued' }) } });
        expect(queued.find('[data-test="status-queued"]').text()).toContain('قيد الإرسال');
        const unknown = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'bot', status: 'unknown', error: 'انتهت مهلة ميتا' }) } });
        expect(unknown.find('[data-test="status-unknown"]').text()).toContain('غير مؤكد — قد تكون وصلت');
        expect(unknown.find('[data-test="status-unknown"]').classes()).toContain('bg-amber-100');
    });

    it('shows delivery ticks for outgoing messages only when the server sends them (E21)', () => {
        const read = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff', status: 'read', status_at: '14:06', multi_fail: false }) } });
        expect(read.find('[data-test="delivery-ticks"]').exists()).toBe(true);
        expect(read.find('[role="img"][data-status]').attributes('aria-label')).toBe('قُرئت 14:06');

        const flagOff = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'bot', status: 'read' }) } });
        expect(flagOff.find('[data-test="delivery-ticks"]').exists()).toBe(false);
        const incoming = mount(MessageBubble, { props: { m: msg({ status: 'read', multi_fail: false }) } });
        expect(incoming.find('[data-test="delivery-ticks"]').exists()).toBe(false);
    });

    it('keeps E19 states and adds the multi-device warning beside the ticks', () => {
        const failed = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'bot', status: 'failed', error: 'x', multi_fail: false }) } });
        expect(failed.text()).toContain('❌ فشل');
        expect(failed.find('[data-test="delivery-ticks"]').exists()).toBe(false);
        const queued = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff', status: 'queued', multi_fail: false }) } });
        expect(queued.find('[data-test="status-queued"]').exists()).toBe(true);

        const multi = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'bot', status: 'delivered', multi_fail: true }) } });
        expect(multi.find('[data-test="multi-fail"]').exists()).toBe(true);
    });

    it('offers copy-to-composer only when the server allows a resend, and emits the message', async () => {
        const m = msg({ direction: 'out', source: 'staff', status: 'failed', error: 'تعذّر التسليم', can_resend: true, body: 'موعدك غداً' });
        const w = mount(MessageBubble, { props: { m } });
        await w.find('[data-test="copy-to-composer"]').trigger('click');
        expect(w.emitted('resend')[0][0]).toEqual(m);

        const no = mount(MessageBubble, { props: { m: { ...m, can_resend: false } } });
        expect(no.find('[data-test="copy-to-composer"]').exists()).toBe(false);
    });

    it('labels deterministic rule replies and colours them apart (E37)', () => {
        const w = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'rule', body: 'مكتبنا في عمّان' }) } });
        expect(w.text()).toContain('⚡ قاعدة');
        expect(w.html()).toContain('bg-teal-600');
    });

    it('shows 👁 on inbound messages the customer was told were read (E35)', () => {
        const seen = mount(MessageBubble, { props: { m: msg({ id: 5 }), readUpto: 7 } });
        expect(seen.find('[data-test="read-receipt"]').attributes('title')).toBe('أُبلغ العميل بالقراءة');
        expect(mount(MessageBubble, { props: { m: msg({ id: 7 }), readUpto: 7 } }).find('[data-test="read-receipt"]').exists()).toBe(true);
        expect(mount(MessageBubble, { props: { m: msg({ id: 8 }), readUpto: 7 } }).find('[data-test="read-receipt"]').exists()).toBe(false);
        expect(mount(MessageBubble, { props: { m: msg({ id: 5 }) } }).find('[data-test="read-receipt"]').exists()).toBe(false);
        expect(mount(MessageBubble, { props: { m: msg({ id: 5, direction: 'out', source: 'bot' }), readUpto: 7 } }).find('[data-test="read-receipt"]').exists()).toBe(false);
        expect(mount(MessageBubble, { props: { m: msg({ id: 5, is_system: true }), readUpto: 7 } }).find('[data-test="read-receipt"]').exists()).toBe(false);
    });
});
