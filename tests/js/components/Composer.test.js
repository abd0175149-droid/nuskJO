import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Composer from '@/Components/WhatsApp/Composer.vue';

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
