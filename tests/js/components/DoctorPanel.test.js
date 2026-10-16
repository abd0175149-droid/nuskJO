import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DoctorPanel from '@/Components/WhatsApp/DoctorPanel.vue';

const result = (over = {}) => ({
    at: '2026-10-16T07:50:00+03:00', budget: 20, offline: false, duration_ms: 812,
    counts: { ok: 2, warn: 1, error: 1, skip: 1 },
    checks: [
        { section: 'local', level: 'error', code: 'RETRY_AFTER', message: 'retry_after (90 ث) لا يزيد على مهلة ProcessWhatsAppReply (125 ث).', advice: 'ارفع DB_QUEUE_RETRY_AFTER.' },
        { section: 'remote', level: 'warn', code: 'WEBHOOK_FIELDS', message: 'حقول غير مشتركة: security.', advice: 'اشترك فيها.' },
        { section: 'local', level: 'ok', code: 'LOCK_TTL', message: 'قفل المحادثة 180 ث يغطي مهلة الرد 125 ث.', advice: null },
        { section: 'remote', level: 'skip', code: 'WEBHOOK_WABA', message: 'لم يُفحص — الوقت.', advice: null },
        { section: 'remote', level: 'ok', code: 'META_TOKEN', message: 'رمز ميتا صالح ولا ينتهي.', advice: null },
    ],
    ...over,
});

describe('DoctorPanel', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows problems of the last full run with advice, open when something is wrong', () => {
        const w = mount(DoctorPanel, { props: { quick: [], last: result() } });
        expect(w.find('[data-test="doctor-summary"]').text()).toBe('1 خطأ · 1 تحذير');
        expect(w.find('#doctor-body').isVisible()).toBe(true);
        const rows = w.findAll('[data-test="doctor-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('RETRY_AFTER');
        expect(rows[0].text()).toContain('💡 ارفع DB_QUEUE_RETRY_AFTER.');
        expect(rows[1].text()).toContain('ميتا');
    });

    it('shows every check when «المشكلات فقط» is unticked', async () => {
        const w = mount(DoctorPanel, { props: { quick: [], last: result() } });
        await w.find('input[type="checkbox"]').setValue(false);
        expect(w.findAll('[data-test="doctor-row"]')).toHaveLength(5);
        expect(w.text()).toContain('لم يُفحص — الوقت.');
    });

    it('falls back to the quick configuration checks before any full run', () => {
        const w = mount(DoctorPanel, { props: { quick: [{ level: 'warn', code: 'SHORT_VERIFY_TOKEN', message: 'رمز التحقق قصير' }], last: null } });
        expect(w.text()).toContain('لم يُشغَّل فحص كامل بعد');
        expect(w.text()).toContain('رمز التحقق قصير');
        expect(w.find('[data-test="doctor-summary"]').text()).toBe('1 تحذير');
    });

    it('stays collapsed and calm when everything is fine', () => {
        const w = mount(DoctorPanel, { props: { quick: [], last: result({ counts: { ok: 5, warn: 0, error: 0, skip: 0 }, checks: [] }) } });
        expect(w.find('[data-test="doctor-summary"]').text()).toBe('سليم');
        expect(w.find('#doctor-body').isVisible()).toBe(false);
    });

    it('runs the doctor on demand and replaces the result', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { doctor: result({ counts: { ok: 5, warn: 0, error: 0, skip: 0 }, checks: [] }) } });
        const w = mount(DoctorPanel, { props: { quick: [], last: result() } });

        await w.find('[data-test="doctor-run"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/doctor', {}, expect.anything());
        expect(w.text()).toContain('اكتمل الفحص');
        expect(w.find('[data-test="doctor-summary"]').text()).toBe('سليم');
    });

    it('explains the throttle', async () => {
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 429 } });
        const w = mount(DoctorPanel, { props: { quick: [], last: null } });

        await w.find('[data-test="doctor-run"]').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('ثلاث مرات كل 10 دقائق');
    });
});
