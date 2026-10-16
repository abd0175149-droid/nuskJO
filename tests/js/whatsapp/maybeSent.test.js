import axios from 'axios';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Composer from '@/Components/WhatsApp/Composer.vue';
import { MAYBE_SENT, SEND_COOLDOWN_MS, useInbox } from '@/composables/whatsapp/useInbox.js';
import { setResilience } from '@/utils/connectionGuard.js';

// «قد تكون أُرسلت» (E28): نتيجة مجهولة ليست فشلاً. المسودة باقية، والخيط يُستطلع فوراً، والإرسال معطّل 10 ثوانٍ
function inboxWithDraft() {
    vi.spyOn(axios, 'get').mockResolvedValue({ data: { conversations: [], messages: [], updates: [], conversation: {} } });
    const inbox = useInbox({ conversations: [], canReply: true }, () => null);
    inbox.s.activeId = 7;
    inbox.s.thread = { id: 7, window_open: true };
    inbox.draft.value = 'موعدك غداً';
    return inbox;
}

describe('maybe-sent outcome', () => {
    beforeEach(() => { vi.useFakeTimers(); setResilience(true); localStorage.clear(); });
    afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); setResilience(false); });

    for (const [label, failure] of [
        ['a network error', { message: 'Network Error' }],
        ['an axios timeout', { code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded' }],
        ['a 504 from nginx', { response: { status: 504, data: '<html>504 Gateway Time-out</html>' } }],
        ['a 524 from Cloudflare', { response: { status: 524, data: '<html>A timeout occurred</html>' } }],
    ]) {
        it(`${label} keeps the draft, polls at once and blocks sending for 10 seconds`, async () => {
            const inbox = inboxWithDraft();
            vi.spyOn(axios, 'post').mockRejectedValue(failure);

            await inbox.send(async () => true);

            expect(inbox.s.sendNotice).toBe(MAYBE_SENT);
            expect(inbox.s.sendError).toBe('');
            expect(inbox.draft.value).toBe('موعدك غداً');
            expect(inbox.s.sendCooldown).toBe(true);
            expect(axios.get).toHaveBeenCalledWith('/api/whatsapp/conversations/7/messages', expect.anything());

            await inbox.send(async () => true);
            expect(axios.post).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(SEND_COOLDOWN_MS);
            expect(inbox.s.sendCooldown).toBe(false);
        });
    }

    it('a 202 OUTCOME_UNKNOWN shows the server text in amber and retries with the same key', async () => {
        const inbox = inboxWithDraft();
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ status: 202, data: { ok: false, code: 'OUTCOME_UNKNOWN', unknown: true, error: 'قد تكون الرسالة أُرسلت — راجع المحادثة قبل الإعادة (رقم المرجع: AB12)' } });

        await inbox.send(async () => true);
        expect(inbox.s.sendNotice).toContain('رقم المرجع: AB12');
        expect(inbox.draft.value).toBe('موعدك غداً');

        await vi.advanceTimersByTimeAsync(SEND_COOLDOWN_MS);
        post.mockResolvedValue({ status: 200, data: { ok: true, replayed: true } });
        await inbox.send(async () => true);
        const keys = post.mock.calls.map((c) => c[2].headers['Idempotency-Key']);
        expect(keys[0]).toBe(keys[1]);
        expect(inbox.draft.value).toBe('');
    });

    it('an explicit rejection stays a red failure', async () => {
        const inbox = inboxWithDraft();
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 422, data: { ok: false, code: 'META_ERROR', error: 'رفضت ميتا الرسالة' } } });

        await inbox.send(async () => true);
        expect(inbox.s.sendError).toBe('رفضت ميتا الرسالة');
        expect(inbox.s.sendNotice).toBe('');
        expect(inbox.s.sendCooldown).toBe(false);
    });

    it('without the flag the old message stays', async () => {
        setResilience(false);
        const inbox = inboxWithDraft();
        vi.spyOn(axios, 'post').mockRejectedValue({ message: 'Network Error' });

        await inbox.send(async () => true);
        expect(inbox.s.sendError).toBe('تعذّر الإرسال');
        expect(inbox.s.sendNotice).toBe('');
    });

    it('the composer shows the notice in amber and disables sending during the cooldown', async () => {
        const w = mount(Composer, { props: { modelValue: 'مرحبا', canReply: true, windowOpen: true, notice: MAYBE_SENT, cooldown: true, finePointer: true } });
        expect(w.find('[data-test="maybe-sent"]').text()).toContain(MAYBE_SENT);
        expect(w.find('[role="alert"]').exists()).toBe(false);
        expect(w.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        await w.find('textarea').trigger('keydown', { key: 'Enter' });
        expect(w.emitted('submit')).toBeUndefined();

        await w.setProps({ cooldown: false });
        expect(w.find('button[type="submit"]').attributes('disabled')).toBeUndefined();
    });
});
