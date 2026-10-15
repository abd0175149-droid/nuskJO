import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import AppModal from '@/Components/AppModal.vue';

const wrappers = [];
function make(props = {}, slots = {}) {
    const w = mount(AppModal, { props: { open: false, title: 'حذف الفاتورة', ...props }, slots: { default: '<input data-autofocus id="f">', ...slots }, attachTo: document.body });
    wrappers.push(w);
    return w;
}
const cancel = (w) => w.find('dialog').element.dispatchEvent(new Event('cancel', { cancelable: true }));

afterEach(() => {
    while (wrappers.length) wrappers.pop().unmount();
    document.body.innerHTML = '';
});

describe('AppModal', () => {
    it('ties the dialog to its title for screen readers', () => {
        const w = make({ open: true });
        const id = w.find('h2').attributes('id');
        expect(id).toBeTruthy();
        expect(w.find('dialog').attributes('aria-labelledby')).toBe(id);
    });

    it('opens as a modal, focuses [data-autofocus], and returns focus to the opener on close', async () => {
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();
        const w = make();
        await w.setProps({ open: true });
        await nextTick();
        expect(w.find('dialog').element.open).toBe(true);
        expect(document.activeElement?.id).toBe('f');

        await w.setProps({ open: false });
        expect(w.find('dialog').element.open).toBe(false);
        expect(document.activeElement).toBe(opener);
    });

    it('closes on Escape when there is nothing to lose', async () => {
        const w = make({ open: true });
        await nextTick();
        cancel(w);
        expect(w.emitted('close')).toHaveLength(1);
    });

    it('asks before discarding unsaved changes on Escape, inside the same dialog', async () => {
        const w = make({ open: true, dirty: true });
        await nextTick();
        cancel(w);
        await nextTick();
        expect(w.emitted('close')).toBeUndefined();
        expect(w.find('[role="alert"]').text()).toContain('تجاهل التعديلات؟');
        expect(w.findAll('dialog')).toHaveLength(1);

        await w.findAll('[role="alert"] button').find((b) => b.text() === 'متابعة التعديل').trigger('click');
        expect(w.find('[role="alert"]').exists()).toBe(false);
        expect(w.emitted('close')).toBeUndefined();

        cancel(w);
        await nextTick();
        await w.findAll('[role="alert"] button').find((b) => b.text() === 'تجاهل').trigger('click');
        expect(w.emitted('close')).toHaveLength(1);
    });

    it('a backdrop click does not close a dirty form', async () => {
        const w = make({ open: true, dirty: true });
        await nextTick();
        await w.find('dialog').trigger('click');
        expect(w.emitted('close')).toBeUndefined();
        expect(w.find('[role="alert"]').exists()).toBe(true);
    });
});
