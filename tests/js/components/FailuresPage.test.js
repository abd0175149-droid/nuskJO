import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { reload: vi.fn() },
    usePage: () => ({ props: { auth: { isAdmin: true } } }),
}));

const { default: Failures } = await import('@/Pages/WhatsApp/Failures.vue');

// صفحة الإخفاقات والطابور (E24): أربعة تبويبات، وأزرار محروسة بتأكيد، ولا زر لما قد يكون وصل
const message = (over = {}) => ({
    id: 11, at: '2026-10-16 10:00', conversation_id: 7, conversation: '9627•••4567', source: 'bot', status: 'failed', msg_type: 'text',
    snippet: 'السعر يرسله الموظف', meta_code: 131000, error_code: 'META_TRANSIENT', category: 'transient', staff_message: 'خلل مؤقت عند ميتا.',
    action: 'أعد بعد قليل.', attempts: 1, redrives: 0, window_open: true, maybe_delivered: false, can_redrive: true, ...over,
});
const initial = (over = {}) => ({
    messages: [message(), message({ id: 12, status: 'unknown', maybe_delivered: true, can_redrive: false, staff_message: 'قد تكون وصلت — تحقق من المحادثة قبل أي إعادة.' })],
    jobs: [
        { uuid: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', at: '2026-10-16 09:00', job: 'SendPushNotification', queue: 'default', conversation_id: null, error: 'RuntimeException: x', retryable: true, reply_job: false },
        { uuid: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', at: '2026-10-16 09:00', job: 'ProcessWhatsAppReply', queue: 'wa-replies', conversation_id: 7, error: 'boom', retryable: false, reply_job: true },
    ],
    webhooks: { rows: [{ id: 5, at: '2026-10-16 08:00', status: 'dead', attempts: 3, next_attempt_at: null, fields: 'messages:1', error: 'QueryException', sha: 'abcdefabcdef', can_replay: true, needs_force: true }], rejected: { waba_mismatch: 2 } },
    queue: { snapshot: null, age_minutes: null, automated: [], free_tier: 1000 },
    redrive: true,
    generated_at: '2026-10-16 10:05',
    ...over,
});
const layout = { template: '<div><slot name="header" /><slot /></div>' };
const make = (over = {}) => mount(Failures, { props: { initial: initial(over) }, global: { stubs: { SmartLayout: layout, AppLayout: layout } } });

describe('Failures page', () => {
    afterEach(() => vi.restoreAllMocks());

    it('lists failed messages with a redrive button only when allowed', () => {
        const w = make();
        const list = w.find('[data-test="messages"]');
        expect(list.text()).toContain('9627•••4567');
        expect(list.text()).toContain('«السعر يرسله الموظف»');
        expect(list.text()).toContain('عابر — خلل مؤقت عند ميتا.');
        expect(list.text()).toContain('قد تكون وصلت');
        expect(w.find('[data-test="redrive-11"]').exists()).toBe(true);
        expect(w.find('[data-test="redrive-12"]').exists()).toBe(false);
        expect(list.find('a').attributes('href')).toBe('/whatsapp/inbox?conv=7');
    });

    it('asks for confirmation then redrives and refreshes', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true } });
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: initial({ messages: [] }) });
        const w = make();

        await w.find('[data-test="redrive-11"]').trigger('click');
        await flushPromises();

        expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('ستُرسل الرسالة نفسها إلى العميل نفسه'));
        expect(post).toHaveBeenCalledWith('/api/whatsapp/messages/11/redrive', {}, expect.anything());
        expect(get).toHaveBeenCalledWith('/api/whatsapp/failures', expect.anything());
        expect(w.text()).toContain('أُعيد إرسال الرسالة.');
        expect(w.text()).toContain('لا رسائل فاشلة');
    });

    it('does nothing when the confirmation is declined', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        const post = vi.spyOn(axios, 'post');
        const w = make();
        await w.find('[data-test="redrive-11"]').trigger('click');
        await flushPromises();
        expect(post).not.toHaveBeenCalled();
    });

    it('shows the server refusal in Arabic', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 422, data: { ok: false, code: 'WINDOW_CLOSED', error: 'النافذة مغلقة — لا يمكن الإرسال حتى يكتب العميل.' } } });
        const w = make();
        await w.find('[data-test="redrive-11"]').trigger('click');
        await flushPromises();
        expect(w.find('[role="alert"]').text()).toContain('النافذة مغلقة');
    });

    it('retries only allowlisted jobs and explains reply jobs', async () => {
        const w = make();
        await w.find('[data-test="tab-jobs"]').trigger('click');
        expect(w.find('[data-test="retry-aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"]').exists()).toBe(true);
        expect(w.find('[data-test="retry-bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb"]').exists()).toBe(false);
        expect(w.find('[data-test="jobs"]').text()).toContain('ردود البوت لا تُعاد من هنا');
    });

    it('replays a dead webhook only after a second confirmation', async () => {
        const ask = vi.spyOn(window, 'confirm').mockReturnValueOnce(true).mockReturnValueOnce(true);
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true, status: 'processed' } });
        vi.spyOn(axios, 'get').mockResolvedValue({ data: initial() });
        const w = make();
        await w.find('[data-test="tab-webhooks"]').trigger('click');
        expect(w.find('[data-test="rejected"]').text()).toContain('waba_mismatch');

        await w.find('[data-test="replay-5"]').trigger('click');
        await flushPromises();

        expect(ask).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenCalledWith('/api/whatsapp/webhook-events/5/replay', { confirm: true }, expect.anything());
    });

    it('explains a missing snapshot and renders queues and budgets when present', async () => {
        const empty = make();
        await empty.find('[data-test="tab-queue"]').trigger('click');
        expect(empty.find('[data-test="queue"]').text()).toContain('لا لقطة بعد');

        const snapshot = {
            at: 1, queue: { default: { jobs: 2, oldest_age_s: 400, reserved_stuck: 1 } }, failed_jobs: { '1h': 1, '24h': 3 },
            outbound_failed_1h: { auth: 1 }, unknown_sends_1h: 0, webhook_failed_24h: 0, waiting_human: 6, bot_latency_p90_ms_1h: 2500,
            outbound_today: { by_source: { bot: 5, staff: 2 }, total: 7, test: 1 }, service_billable_month: 12, channel_health: 'AVAILABLE', scheduler_beat_age_s: 20,
        };
        const w = make({ queue: { snapshot, age_minutes: 3, automated: [{ kind: 'outage', count: 4, budget: 2, over: true }], free_tier: 1000 } });
        await w.find('[data-test="tab-queue"]').trigger('click');
        const q = w.find('[data-test="queue"]');
        expect(q.text()).toContain('عالقة 1');
        expect(q.text()).toContain('البوت 5');
        expect(q.text()).toContain('تجريبي 1');
        expect(q.find('[data-test="automated"]').text()).toContain('تعطل المزوّد 4/2');
        expect(q.text()).toContain('12 من 1000');
    });
});
