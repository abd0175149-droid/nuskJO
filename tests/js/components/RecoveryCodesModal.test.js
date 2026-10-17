import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import RecoveryCodesModal from '@/Components/Security/RecoveryCodesModal.vue';

const CODES = Array.from({ length: 10 }, (_, i) => `ABCDE-FGH${String(i).padStart(2, '2')}`.slice(0, 11));

let wrapper = null;
afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
});

describe('RecoveryCodesModal', () => {
    it('shows the ten codes once and closes only after the user confirms saving them', async () => {
        wrapper = mount(RecoveryCodesModal, { props: { codes: CODES }, attachTo: document.body });
        await nextTick();
        await nextTick();

        expect(wrapper.findAll('li')).toHaveLength(10);
        const done = wrapper.findAll('button').find((b) => b.text() === 'تم');
        expect(done.attributes('disabled')).toBeDefined();

        // الإغلاق من رأس النافذة قبل التأكيد: تنبيه ولا إغلاق
        await wrapper.find('header button').trigger('click');
        expect(wrapper.emitted('close')).toBeUndefined();
        expect(wrapper.find('[role="alert"]').text()).toContain('حفظت الرموز');

        await wrapper.find('input[type="checkbox"]').setValue(true);
        await done.trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
