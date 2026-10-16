import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import SendFileConfirm from '@/Components/WhatsApp/SendFileConfirm.vue';

// حوار تأكيد المرفق (E32.10): المستلم بالاسم وآخر 4 أرقام، ونوع الوثيقة إلزامي للمستند، وتأكيد ثانٍ للتأشيرة
const DOC_KINDS = { invoice: 'فاتورة', visa: 'تأشيرة', ticket: 'تذكرة', other: 'أخرى' };
const recipient = { name: 'أبو أحمد', last4: '1234', clientCode: 'C-17' };
const wrappers = [];

function make(props = {}) {
    const w = mount(SendFileConfirm, {
        props: { open: true, file: new File([new Uint8Array(2048)], 'visa.pdf', { type: 'application/pdf' }), kind: 'document', recipient, docKinds: DOC_KINDS, ...props },
        attachTo: document.body,
    });
    wrappers.push(w);
    return w;
}
const sendButton = (w) => w.find('[data-test="send-file"]');

afterEach(() => {
    while (wrappers.length) wrappers.pop().unmount();
    document.body.innerHTML = '';
});

describe('SendFileConfirm', () => {
    it('names the file, its size and the recipient without the full number', () => {
        const w = make();
        expect(w.find('[data-test="file-line"]').text()).toContain('visa.pdf');
        const line = w.find('[data-test="recipient-line"]').text();
        expect(line).toContain('أبو أحمد');
        expect(line).toContain('…1234');
        expect(line).toContain('C-17');
        expect(w.find('[data-test="document-warning"]').exists()).toBe(true);
    });

    it('requires a document kind before sending a document', async () => {
        const w = make();
        expect(sendButton(w).attributes('disabled')).toBeDefined();
        await w.find('select').setValue('ticket');
        expect(sendButton(w).attributes('disabled')).toBeUndefined();
        await w.find('textarea').setValue('  تذكرتك  ');
        await sendButton(w).trigger('click');
        expect(w.emitted('confirm')[0][0]).toEqual({ caption: 'تذكرتك', docKind: 'ticket', confirmIdentity: false });
    });

    it('asks to confirm the visa holder before sending a visa', async () => {
        const w = make();
        await w.find('select').setValue('visa');
        expect(w.find('[data-test="visa-confirm"]').text()).toContain('أبو أحمد');
        expect(sendButton(w).attributes('disabled')).toBeDefined();
        await w.find('[data-test="visa-confirm"] input').setValue(true);
        expect(sendButton(w).attributes('disabled')).toBeUndefined();
        await sendButton(w).trigger('click');
        expect(w.emitted('confirm')[0][0]).toMatchObject({ docKind: 'visa', confirmIdentity: true });
    });

    it('defaults a photo to «other», so it sends at once', async () => {
        const w = make({ kind: 'image', file: new File([new Uint8Array(8)], 'p.jpg', { type: 'image/jpeg' }) });
        await nextTick();
        expect(w.find('[data-test="document-warning"]').exists()).toBe(false);
        expect(sendButton(w).attributes('disabled')).toBeUndefined();
        await sendButton(w).trigger('click');
        expect(w.emitted('confirm')[0][0]).toMatchObject({ docKind: 'other', caption: '' });
    });

    it('starts clean every time it opens', async () => {
        const w = make();
        await w.find('select').setValue('visa');
        await w.find('textarea').setValue('نص');
        await w.setProps({ open: false });
        await w.setProps({ open: true });
        expect(w.find('select').element.value).toBe('');
        expect(w.find('textarea').element.value).toBe('');
        expect(w.find('[data-test="visa-confirm"]').exists()).toBe(false);
    });
});
