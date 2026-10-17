import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { confirm, confirmState, prompt } from '@/composables/useConfirm.js';

let host = null;
const mountHost = () => (host = mount(ConfirmDialog, { attachTo: document.body }));
const button = (label) => host.findAll('button').find((b) => b.text() === label);

afterEach(() => {
    host?.unmount();
    host = null;
    document.body.innerHTML = '';
});

describe('useConfirm', () => {
    it('resolves true on confirm and false on cancel', async () => {
        mountHost();
        const yes = confirm({ title: 'اعتماد الفاتورة؟', message: 'ستتحدث الأرصدة.', confirmLabel: 'اعتماد' });
        await nextTick();
        expect(host.find('h2').text()).toBe('اعتماد الفاتورة؟');
        await button('اعتماد').trigger('click');
        expect(await yes).toBe(true);

        const no = confirm({ title: 'حذف؟', danger: true, confirmLabel: 'حذف' });
        await nextTick();
        await button('إلغاء').trigger('click');
        expect(await no).toBe(false);
    });

    it('a dangerous action is red and the focus starts on cancel', async () => {
        mountHost();
        const p = confirm({ title: 'حذف القيد؟', danger: true, confirmLabel: 'حذف' });
        await nextTick();
        await nextTick();
        expect(button('حذف').classes()).toContain('bg-red-600');
        expect(document.activeElement?.textContent.trim()).toBe('إلغاء');
        await button('إلغاء').trigger('click');
        await p;
    });

    it('prompt returns the trimmed text, or null when cancelled', async () => {
        mountHost();
        const reason = prompt({ title: 'رفض الفاتورة', label: 'سبب الرفض' });
        await nextTick();
        await host.find('textarea').setValue('  مبلغ غير صحيح ');
        await button('تأكيد').trigger('click');
        expect(await reason).toBe('مبلغ غير صحيح');

        const none = prompt({ title: 'رفض', label: 'سبب الرفض' });
        await nextTick();
        host.find('dialog').element.dispatchEvent(new Event('cancel', { cancelable: true }));
        expect(await none).toBeNull();
    });

    it('a required field cannot be confirmed empty', async () => {
        mountHost();
        const reason = prompt({ title: 'رفض السلفة', label: 'سبب الرفض', required: true });
        await nextTick();
        await button('تأكيد').trigger('click');
        expect(host.text()).toContain('هذا الحقل مطلوب');
        expect(confirmState.current).not.toBeNull();
        await host.find('textarea').setValue('لا رصيد كافٍ');
        await button('تأكيد').trigger('click');
        expect(await reason).toBe('لا رصيد كافٍ');
    });

    it('queues concurrent requests and cancels what is pending when the page goes away', async () => {
        mountHost();
        const first = confirm({ title: 'الأول' });
        const second = confirm({ title: 'الثاني' });
        await nextTick();
        expect(host.find('h2').text()).toBe('الأول');
        await button('تأكيد').trigger('click');
        expect(await first).toBe(true);
        await nextTick();
        expect(host.find('h2').text()).toBe('الثاني');
        host.unmount();
        host = null;
        expect(await second).toBe(false);
    });
});
