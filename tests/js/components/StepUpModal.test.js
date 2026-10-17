import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';

const page = reactive({ props: { mfa: { stepup_method: 'totp' } } });
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));
const post = vi.fn();
vi.mock('axios', () => ({ default: { post: (...args) => post(...args) } }));

const { default: StepUpModal } = await import('@/Components/Security/StepUpModal.vue');
const { requestStepUp, settleStepUp, stepUpState } = await import('@/composables/useStepUp.js');

let wrapper = null;
const button = (label) => wrapper.findAll('button').find((b) => b.text() === label);
const flush = async () => { await nextTick(); await nextTick(); };

beforeEach(() => {
    wrapper = mount(StepUpModal, { attachTo: document.body });
    post.mockReset();
});
afterEach(() => {
    settleStepUp(false);
    stepUpState.viaFlash = false;
    stepUpState.replayable = false;
    wrapper.unmount();
    document.body.innerHTML = '';
    page.props.mfa.stepup_method = 'totp';
});

describe('StepUpModal', () => {
    it('asks for the app code and resolves the waiting request after the server accepts it', async () => {
        const result = requestStepUp();
        await flush();
        expect(wrapper.text()).toContain('رمز التحقق (6 أرقام)');
        expect(wrapper.find('input').attributes('autocomplete')).toBe('one-time-code');

        post.mockResolvedValueOnce({ data: { ok: true } });
        await wrapper.find('input').setValue('123456');
        await button('تأكيد').trigger('click');
        await flush();

        expect(post).toHaveBeenCalledWith('/security/confirm', { code: '123456' }, { headers: { Accept: 'application/json' } });
        expect(await result).toBe(true);
        expect(stepUpState.open).toBe(false);
    });

    it('shows the server error and stays open, and cancel resolves false', async () => {
        page.props.mfa.stepup_method = 'password';
        const result = requestStepUp();
        await flush();
        expect(wrapper.find('input').attributes('type')).toBe('password');

        post.mockRejectedValueOnce({ response: { status: 422, data: { errors: { password: ['كلمة المرور غير صحيحة.'] } } } });
        await wrapper.find('input').setValue('wrong');
        await button('تأكيد').trigger('click');
        await flush();
        expect(wrapper.find('[role="alert"]').text()).toBe('كلمة المرور غير صحيحة.');
        expect(stepUpState.open).toBe(true);

        await button('إلغاء').trigger('click');
        expect(await result).toBe(false);
    });

    it('after a bounced visit with nothing to replay it tells the user to repeat the action', async () => {
        stepUpState.viaFlash = true;
        stepUpState.replayable = false;
        const result = requestStepUp();
        await flush();
        post.mockResolvedValueOnce({ data: { ok: true } });
        await wrapper.find('input').setValue('123456');
        await button('تأكيد').trigger('click');
        await flush();

        expect(wrapper.text()).toContain('تم التحقق — أعد تنفيذ العملية');
        await button('حسناً').trigger('click');
        expect(await result).toBe(true);
    });
});
