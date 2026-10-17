import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import Composer from '@/Components/WhatsApp/Composer.vue';
import SendFileConfirm from '@/Components/WhatsApp/SendFileConfirm.vue';

const make = (props = {}) => mount(Composer, { props: { modelValue: 'مرحبا', canReply: true, windowOpen: true, ...props } });

describe('Composer', () => {
    it('sends on Enter with a fine pointer, and Shift+Enter keeps a new line', async () => {
        const w = make({ finePointer: true });
        await w.find('textarea').trigger('keydown', { key: 'Enter' });
        expect(w.emitted('submit')).toHaveLength(1);
        await w.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: true });
        expect(w.emitted('submit')).toHaveLength(1);
        expect(w.text()).toContain('Enter للإرسال');
    });

    it('never sends on Enter on touch screens; the button sends', async () => {
        const w = make({ finePointer: false });
        await w.find('textarea').trigger('keydown', { key: 'Enter' });
        expect(w.emitted('submit')).toBeUndefined();
        expect(w.text()).not.toContain('Enter للإرسال');
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')).toHaveLength(1);
    });

    it('is disabled when the 24h window is closed', async () => {
        const w = make({ windowOpen: false, finePointer: true });
        expect(w.text()).toContain('انتهت نافذة الـ24 ساعة');
        expect(w.find('textarea').attributes('disabled')).toBeDefined();
        expect(w.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')).toBeUndefined();
    });

    it('does not send an empty draft, and warns when the window is about to close', async () => {
        const w = make({ modelValue: '   ', windowLeft: 90, finePointer: true });
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')).toBeUndefined();
        expect(w.text()).toContain('⏳ تبقّى');
    });

    it('explains a missing reply permission instead of showing the form', () => {
        const w = make({ canReply: false });
        expect(w.find('form').exists()).toBe(false);
        expect(w.text()).toContain('لا تملك صلاحية الردّ على المحادثات');
    });

    it('emits model updates as the user types', async () => {
        const w = make({ modelValue: '' });
        await w.find('textarea').setValue('أهلاً');
        expect(w.emitted('update:modelValue')[0]).toEqual(['أهلاً']);
    });
});

describe('Composer quick replies (E17)', () => {
    it('blocks sending while a {{variable}} is unfilled and says which', async () => {
        const w = make({ modelValue: 'أهلاً {{ name }}', finePointer: true });
        expect(w.text()).toContain('أكمل قبل الإرسال');
        expect(w.text()).toContain('{{name}}');
        expect(w.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')).toBeUndefined();
    });

    it('shows the quick replies button only when enabled, disabled with a closed window', () => {
        expect(make().find('button[aria-label="ردود جاهزة"]').exists()).toBe(false);
        const on = make({ quickReplies: true, conversationId: 3 });
        expect(on.find('button[aria-label="ردود جاهزة"]').attributes('disabled')).toBeUndefined();
        const closed = make({ quickReplies: true, conversationId: 3, windowOpen: false });
        expect(closed.find('button[aria-label="ردود جاهزة"]').attributes('disabled')).toBeDefined();
    });
});

describe('Composer attachments and emoji (E32)', () => {
    const MB = 1024 * 1024;
    const mediaPolicy = {
        enabled: true, accept: '.pdf,.jpg', max_caption: 1024, doc_kinds: { visa: 'تأشيرة', other: 'أخرى' },
        types: [{ mime: 'application/pdf', kind: 'document', max: 20 * MB, exts: ['pdf'] }, { mime: 'image/jpeg', kind: 'image', max: 5 * MB, exts: ['jpg', 'jpeg'] }],
    };
    const mounted = [];
    const attached = (props = {}) => {
        const w = mount(Composer, { props: { modelValue: '', canReply: true, windowOpen: true, finePointer: true, ...props }, attachTo: document.body });
        mounted.push(w);
        return w;
    };
    const pick = async (w, file) => {
        const input = w.find('[data-test="file-input"]');
        Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
        await input.trigger('change');
        await flushPromises();
    };
    afterEach(() => {
        while (mounted.length) mounted.pop().unmount();
        document.body.innerHTML = '';
    });

    it('shows the attach button only when file sending is on', () => {
        expect(make().find('[data-test="attach"]').exists()).toBe(false);
        expect(make({ mediaPolicy: { ...mediaPolicy, enabled: false } }).find('[data-test="attach"]').exists()).toBe(false);
        const on = make({ mediaPolicy, conversationId: 3 });
        expect(on.find('[data-test="attach"]').exists()).toBe(true);
        expect(on.find('[data-test="file-input"]').attributes('accept')).toBe('.pdf,.jpg');
        expect(make({ mediaPolicy, windowOpen: false }).find('[data-test="attach"]').attributes('disabled')).toBeDefined();
    });

    it('refuses a disallowed file at once, without a dialog', async () => {
        const w = attached({ mediaPolicy, conversationId: 3 });
        await pick(w, new File(['x'], 'run.exe', { type: 'application/x-msdownload' }));
        expect(w.find('[data-test="attach-error"]').text()).toContain('نوع الملف غير مسموح');
        expect(w.findComponent(SendFileConfirm).props('open')).toBe(false);
        expect(w.emitted('sendFile')).toBeUndefined();
    });

    it('sends a file only through the confirmation, for the conversation it was picked in', async () => {
        const w = attached({ mediaPolicy, conversationId: 3, recipient: { name: 'سالم', last4: '1234' } });
        await pick(w, new File([new Uint8Array(10)], 'visa.pdf', { type: 'application/pdf' }));
        const dialog = w.findComponent(SendFileConfirm);
        expect(dialog.props('open')).toBe(true);
        expect(dialog.props('kind')).toBe('document');
        expect(w.emitted('sendFile')).toBeUndefined();

        await w.setProps({ conversationId: 4, recipient: { name: 'عميل آخر', last4: '3333' } });
        expect(dialog.props('recipient')).toEqual({ name: 'سالم', last4: '1234' });
        dialog.vm.$emit('confirm', { caption: 'تأشيرتك', docKind: 'visa', confirmIdentity: true });
        await nextTick();
        expect(w.emitted('sendFile')[0][0]).toMatchObject({ conversationId: 3, kind: 'document', caption: 'تأشيرتك', docKind: 'visa', confirmIdentity: true });
        expect(dialog.props('open')).toBe(false);
        // Enter لا يرسل ملفاً ولا نصاً فارغاً
        await w.find('textarea').trigger('keydown', { key: 'Enter' });
        expect(w.emitted('submit')).toBeUndefined();
    });

    it('inserts an emoji at the cursor', async () => {
        const w = attached({ modelValue: 'مرحبا بك' });
        w.find('textarea').element.setSelectionRange(5, 5);
        await w.find('button[aria-label="رموز تعبيرية"]').trigger('click');
        // محدّد السمات في jsdom لا يطابق رموز ما فوق BMP، فالبحث بالقيمة
        await w.findAll('button').find((b) => b.attributes('aria-label') === '🙏').trigger('click');
        expect(w.emitted('update:modelValue').at(-1)).toEqual(['مرحبا🙏 بك']);
        expect(w.find('[role="dialog"][aria-label="رموز تعبيرية"]').exists()).toBe(false);
    });
});
