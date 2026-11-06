import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: {} }),
}));

const { default: Status } = await import('@/Pages/Ops/Status.vue');
const { age, level } = await import('@/utils/opsStatus.js');

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
            level: 'error', configured: true,
            gateway: { configured: true, reachable: false, version: null, error: 'GW_UNREACHABLE' },
            outbox: { pending: 3, deferred: 1, awaiting_approval: 0, overdue: 2 },
            intake: { last_signed_at: '2026-10-16T09:58:00+03:00', signatures: { day: 0 }, dead_open: 0 },
            transport: 'openwa',
        },
        numbers: { level: 'error', state: 'ok', numbers: [
            { name: 'المبيعات', phone: '962790000001', status: 'ready', ready: true, paused_until: null, probe_failures: 0, last_probe_ok_at: '2026-10-16T09:57:00+03:00', last_inbound_at: null },
            { name: 'الحجوزات', phone: null, status: 'disconnected', ready: false, paused_until: null, probe_failures: 3, last_probe_ok_at: null, last_inbound_at: null },
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

    it('shows the gateway and the outbox backlog', () => {
        const w = make();
        expect(w.find('[data-test="gateway"]').text()).toContain('⛔ لا تستجيب');
        expect(w.find('[data-test="gateway"]').text()).toContain('GW_UNREACHABLE');
        expect(w.find('[data-test="outbox"]').text()).toContain('ينتظر: 3');
        expect(w.find('[data-test="outbox"]').text()).toContain('2 رسالة تأخرت');
    });

    it('lists our numbers and flags a disconnected one', () => {
        const w = make();
        const rows = w.findAll('[data-test="number-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('المبيعات');
        expect(rows[1].classes().join(' ')).toContain('bg-red-50');
        expect(rows[1].text()).toContain('3 إخفاق');
        expect(w.find('[data-test="section-numbers"]').find('button').exists()).toBe(false);
    });

    it('says so when there is no active number', () => {
        const s = snapshot();
        s.sections.numbers = { level: 'unknown', state: 'none', numbers: [] };
        expect(make(s).find('[data-test="section-numbers"]').text()).toContain('لا رقم فعّالاً');
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

    it('formats ages and levels', () => {
        expect(age(null)).toBe('—');
        expect(age(45)).toBe('45 ث');
        expect(age(600)).toBe('10 د');
        expect(age(7200)).toBe('2 س');
        expect(level('nonsense').label).toBe('غير معروف');
    });
});
