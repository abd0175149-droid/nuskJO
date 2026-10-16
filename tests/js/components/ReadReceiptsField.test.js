import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ReadReceiptsField from '@/Components/WhatsApp/ReadReceiptsField.vue';

describe('ReadReceiptsField (E35)', () => {
    it('offers the three modes with their meaning and marks the current one', () => {
        const w = mount(ReadReceiptsField, { props: { modelValue: 'on_reply', flagOn: true } });
        const radios = w.findAll('input[type="radio"]');
        expect(radios.map((r) => r.element.value)).toEqual(['off', 'on_reply', 'on_open']);
        expect(radios[1].element.checked).toBe(true);
        expect(w.text()).toContain('لا يرى العميل أي علامة قراءة ولا مؤشر كتابة');
        expect(w.text()).toContain('«يكتب…» أثناء تحضير البوت للرد، وعلامتا القراءة عند رد الموظف');
        expect(w.text()).toContain('عند فتح الموظف الموكَّل للمحادثة');
    });

    it('always warns that typing shows the blue ticks', () => {
        const w = mount(ReadReceiptsField, { props: { modelValue: 'off' } });
        expect(w.find('[role="note"]').text()).toContain('يُظهر العلامتين الزرقاوين حتماً');
    });

    it('emits the chosen mode', async () => {
        const w = mount(ReadReceiptsField, { props: { modelValue: 'off', flagOn: true } });
        await w.findAll('input[type="radio"]')[2].trigger('change');
        expect(w.emitted('update:modelValue')[0]).toEqual(['on_open']);
    });

    it('says plainly when the flag keeps everything off', () => {
        expect(mount(ReadReceiptsField, { props: { modelValue: 'on_reply', flagOn: false } }).find('[data-test="flag-off"]').exists()).toBe(true);
        expect(mount(ReadReceiptsField, { props: { modelValue: 'on_reply', flagOn: true } }).find('[data-test="flag-off"]').exists()).toBe(false);
    });

    it('shows the server error and the dirty ring', () => {
        const w = mount(ReadReceiptsField, { props: { modelValue: 'off', dirty: true, error: 'قيمة غير مسموحة' } });
        expect(w.text()).toContain('قيمة غير مسموحة');
        expect(w.find('[data-test="read-receipts-field"]').classes()).toContain('ring-1');
    });
});
