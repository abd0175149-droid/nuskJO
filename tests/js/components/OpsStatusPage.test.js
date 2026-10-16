import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: {} }),
}));

const { default: Status } = await import('@/Pages/Ops/Status.vue');
const { age, issueLabel, level } = await import('@/utils/opsStatus.js');

// صفحة «حالة النظام» (E23): بطاقات الأقسام وشاراتها، وقسم واتساب والويبهوك، وجدول أرقام الحساب، والتحديث
const snapshot = (over = {}) => ({
    at: '2026-10-16T10:00:00+03:00',
    sections: {
        app: { level: 'ok', env: 'production', debug: false, php: '8.3.6', laravel: '13.0.0', build: null },
        database: { level: 'ok', latency_ms: 2, size_mb: 41.5, sqlite: '3.45.1', journal_mode: 'wal', busy_timeout: 5000 },
        disk: { level: 'warn', storage: { used_pct: 92, free_gb: 3.1 }, database: { used_pct: 92, free_gb: 3.1 } },
        queue: { level: 'ok', retry_after: 240, failed_24h: 0, queues: { default: { available: 0, oldest_seconds: null, stuck: 0 }, 'wa-replies': { available: 1, oldest_seconds: 4, stuck: 0 } } },
        beats: { level: 'ok', codes: [], schedule_age: 20, queues: { default: 30 } },
        backups: { level: 'ok', enabled: true, items: { snapshot: { at: '2026-10-16T02:30:00+03:00', age_hours: 7, state: 'ok' } } },
        doctor: { level: 'error', at: '2026-10-16T07:50:00+03:00', counts: { ok: 9, warn: 1, error: 1, skip: 2 }, problems: [{ level: 'error', code: 'RETRY_AFTER', message: 'retry_after قصير' }] },
        whatsapp: {
            level: 'error', configured: true, monitor: true,
            health: { state: 'AVAILABLE', label: 'تعمل' },
            channel: { state: 'ok', can_send_message: 'AVAILABLE', quality_rating: 'GREEN', number: { status: 'CONNECTED', name_status: 'APPROVED', throughput: 'STANDARD' }, checked_at: '2026-10-16T09:37:00+03:00' },
            webhook: { ok: false, issues: ['callback_mismatch', 'missing:security'], callback: { host: 'aibot-platform.example.com', ours: false }, field_versions: { messages: 'v25.0' } },
            intake: { last_signed_at: '2026-10-16T09:58:00+03:00', signatures: { day: 0 }, dead_open: 0 },
            graph: { requests: { version: 'v25.0', sunset: '2028-07-29' }, webhook: { version: null }, appsecret_proof: true },
            credentials: { state: 'ok', never_expires: true }, transport: 'cloud',
        },
        numbers: { level: 'warn', state: 'ok', at: '2026-10-16T09:07:00+03:00', numbers: [
            { display: '15550783881', verified_name: 'Test Travel', quality_rating: 'GREEN', status: 'CONNECTED', name_status: 'APPROVED', code_verification_status: 'VERIFIED', throughput: 'STANDARD', ours: true },
            { display: '16505553333', verified_name: 'Branch', quality_rating: 'RED', status: 'PENDING', name_status: 'PENDING_REVIEW', code_verification_status: 'NOT_VERIFIED', throughput: null, ours: false },
        ] },
        reverb: { level: 'unknown', enabled: false },
    },
    ...over,
});
const layout = { template: '<div><slot name="header" /><slot /></div>' };
const make = (status = snapshot()) => mount(Status, { props: { status }, global: { stubs: { AppLayout: layout } } });

describe('Ops status page', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows every section with its level badge', () => {
        const w = make();
        expect(w.find('[data-test="section-disk"]').text()).toContain('تحذير');
        expect(w.find('[data-test="section-doctor"]').text()).toContain('retry_after قصير');
        expect(w.find('[data-test="section-app"]').text()).toContain('غير معروف');
        expect(w.find('[data-test="section-queue"]').text()).toContain('ردود البوت');
        expect(w.find('[data-test="section-reverb"]').text()).toContain('غير مفعّل');
    });

    it('explains webhook drift by host only', () => {
        const hook = make().find('[data-test="webhook"]');
        expect(hook.text()).toContain('⚠️ انحراف');
        expect(hook.text()).toContain('رابط الويبهوك لا يشير إلى نُسك');
        expect(hook.text()).toContain('حقل security غير مشترك');
        expect(hook.text()).toContain('aibot-platform.example.com');
    });

    it('lists the account numbers, marks ours and flags another in trouble', () => {
        const w = make();
        const rows = w.findAll('[data-test="number-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('رقم نُسك');
        expect(rows[1].classes().join(' ')).toContain('bg-amber-50');
        expect(w.find('[data-test="section-numbers"]').text()).toContain('WhatsApp Manager');
        expect(w.find('[data-test="section-numbers"]').find('button').exists()).toBe(false);
    });

    it('asks for the WABA ID when it is unknown', () => {
        const s = snapshot();
        s.sections.numbers = { level: 'unknown', state: 'no_waba', numbers: [] };
        expect(make(s).find('[data-test="section-numbers"]').text()).toContain('أدخل WA_WABA_ID');
    });

    it('refreshes on demand and explains the limit', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValueOnce({ data: snapshot({ at: '2026-10-16T11:00:00+03:00' }) })
            .mockRejectedValueOnce({ response: { status: 429 } });
        const w = make();

        await w.find('[data-test="refresh"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/api/ops/status/refresh', {}, expect.anything());

        await w.find('[data-test="refresh"]').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('ستّ مرات في الدقيقة');
    });

    it('formats ages, levels and issues', () => {
        expect(age(null)).toBe('—');
        expect(age(45)).toBe('45 ث');
        expect(age(600)).toBe('10 د');
        expect(age(7200)).toBe('2 س');
        expect(level('nonsense').label).toBe('غير معروف');
        expect(issueLabel('override:waba')).toBe('override على الحساب يوجّه لغيرنا');
    });
});
