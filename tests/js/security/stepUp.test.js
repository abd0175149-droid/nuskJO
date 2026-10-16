import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    handleVisitResult, installStepUpInterceptor, registerStepUpHost, rememberVisit, settleStepUp, stepUpState,
} from '@/composables/useStepUp.js';

// axios وهمي: يلتقط معالج الخطأ ويعيد الطلب المُعاد
function fakeAxios() {
    const ax = { handler: null, request: vi.fn(async (config) => ({ data: 'retried', config })) };
    ax.interceptors = { response: { use: (_ok, onError) => { ax.handler = onError; return 1; } } };
    return ax;
}
const error = (status, code, config = { url: '/x', method: 'post', data: '{"a":1}' }) => ({ response: { status, data: { code } }, config });

let host = null;
afterEach(() => {
    settleStepUp(false);
    host?.release();
    host = null;
    stepUpState.viaFlash = false;
    stepUpState.replayable = false;
    handleVisitResult({ props: {} }, () => {});
});

describe('step-up interceptor', () => {
    it('opens the dialog on 423 and resends the original request once after confirming', async () => {
        host = registerStepUpHost();
        const ax = fakeAxios();
        installStepUpInterceptor(ax);

        const pending = ax.handler(error(423, 'STEP_UP_REQUIRED'));
        expect(stepUpState.open).toBe(true);
        settleStepUp(true);

        const res = await pending;
        expect(res.data).toBe('retried');
        expect(ax.request).toHaveBeenCalledOnce();
        expect(ax.request.mock.calls[0][0]).toMatchObject({ url: '/x', method: 'post', data: '{"a":1}', stepUpRetried: true });
    });

    it('rejects with the original error when cancelled, when no dialog is mounted, or on a second 423', async () => {
        const ax = fakeAxios();
        installStepUpInterceptor(ax);
        const noHost = error(423, 'STEP_UP_REQUIRED');
        await expect(ax.handler(noHost)).rejects.toBe(noHost);
        expect(stepUpState.open).toBe(false);

        host = registerStepUpHost();
        const cancelled = error(423, 'STEP_UP_REQUIRED');
        const pending = ax.handler(cancelled);
        settleStepUp(false);
        await expect(pending).rejects.toBe(cancelled);

        const again = error(423, 'STEP_UP_REQUIRED', { url: '/x', stepUpRetried: true });
        await expect(ax.handler(again)).rejects.toBe(again);
        expect(ax.request).not.toHaveBeenCalled();
    });

    it('concurrent requests share one dialog and are all resent', async () => {
        host = registerStepUpHost();
        const ax = fakeAxios();
        installStepUpInterceptor(ax);

        const a = ax.handler(error(423, 'STEP_UP_REQUIRED', { url: '/a' }));
        const b = ax.handler(error(423, 'STEP_UP_REQUIRED', { url: '/b' }));
        settleStepUp(true);

        await Promise.all([a, b]);
        expect(ax.request.mock.calls.map((c) => c[0].url)).toEqual(['/a', '/b']);
    });

    it('sends a session that has not passed the challenge, or must enrol, to its page', async () => {
        const ax = fakeAxios();
        const onMfaRequired = vi.fn();
        const onEnrollRequired = vi.fn();
        installStepUpInterceptor(ax, { onMfaRequired, onEnrollRequired });

        await expect(ax.handler(error(401, 'MFA_REQUIRED'))).rejects.toBeTruthy();
        await expect(ax.handler(error(403, 'MFA_ENROLL_REQUIRED'))).rejects.toBeTruthy();
        await expect(ax.handler(error(403, 'OTHER'))).rejects.toBeTruthy();
        expect(onMfaRequired).toHaveBeenCalledOnce();
        expect(onEnrollRequired).toHaveBeenCalledOnce();
    });
});

describe('Inertia visits bounced for step-up', () => {
    const post = { method: 'post', url: new URL('http://nusk.test/invoices/7/approve'), data: { note: 'x' } };

    it('replays the last non-GET visit after confirming', async () => {
        const visit = vi.fn();
        rememberVisit(post);
        rememberVisit({ method: 'get', url: new URL('http://nusk.test/invoices') });

        handleVisitResult({ props: { flash: { stepup_required: true } } }, visit);
        expect(stepUpState).toMatchObject({ open: true, viaFlash: true, replayable: true });
        settleStepUp(true);
        await Promise.resolve();

        expect(visit).toHaveBeenCalledWith('http://nusk.test/invoices/7/approve', { method: 'post', data: { note: 'x' }, preserveScroll: true });
        expect(stepUpState.viaFlash).toBe(false);
    });

    it('a visit that finished normally is never replayed later, and cancelling replays nothing', async () => {
        const visit = vi.fn();
        rememberVisit(post);
        handleVisitResult({ props: { flash: {} } }, visit);
        handleVisitResult({ props: { flash: { stepup_required: true } } }, visit);
        expect(stepUpState.replayable).toBe(false);
        settleStepUp(true);
        await Promise.resolve();

        rememberVisit(post);
        handleVisitResult({ props: { flash: { stepup_required: true } } }, visit);
        settleStepUp(false);
        await Promise.resolve();
        expect(visit).not.toHaveBeenCalled();
    });
});
