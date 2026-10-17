import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MetaLastErrorCard from '@/Components/WhatsApp/MetaLastErrorCard.vue';

const last = { meta_code: 190, error_code: 'TOKEN_INVALID', category: 'auth', message: 'رمز ميتا منتهٍ أو مُلغى', action: 'ولّد رمزاً جديداً',
    fbtrace_id: 'Ax1', at: '2026-10-16T09:00:00+03:00', count_24h: 3 };
const today = { transient: 0, throttle: 0, window: 1, auth: 3, integrity: 0, quality: 0, caller: 2, unknown: 0 };

describe('MetaLastErrorCard', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows the last channel error with its action, count and trace, and today per category', () => {
        const w = mount(MetaLastErrorCard, { props: { last, today, proof: null } });
        expect(w.text()).toContain('رمز ميتا منتهٍ أو مُلغى');
        expect(w.text()).toContain('TOKEN_INVALID · 190');
        expect(w.text()).toContain('الإجراء: ولّد رمزاً جديداً');
        expect(w.text()).toContain('3 مرة خلال 24 ساعة');
        expect(w.text()).toContain('Ax1');
        expect(w.text()).toContain('اعتماد: 3');
        expect(w.text()).toContain('الطلب: 2');
    });

    it('«تمّ الحل» posts the clear request and reports back', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true, last_error: null } });
        const w = mount(MetaLastErrorCard, { props: { last, today, proof: null } });

        await w.find('button').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/settings/last-error/clear', {}, expect.anything());
        expect(w.emitted('cleared')).toHaveLength(1);
    });

    it('says when there is nothing to clear', () => {
        const w = mount(MetaLastErrorCard, { props: { last: null, today, proof: null } });
        expect(w.text()).toContain('لا خطأ على مستوى القناة');
        expect(w.find('button').exists()).toBe(false);
    });

    it('reports the appsecret_proof share and warns below 100% or on rejection', () => {
        const off = mount(MetaLastErrorCard, { props: { last: null, today, proof: { enabled: false } } });
        expect(off.find('[data-test="proof-line"]').text()).toContain('مطفأ');

        const full = mount(MetaLastErrorCard, { props: { last: null, today, proof: { enabled: true, days: 7, calls: 40, proof: 40, proof_err: 0, percent: 100, ready_for_require: true } } });
        expect(full.find('[data-test="proof-line"]').text()).toContain('مُرسل في 100% من 40 نداء منذ 7 أيام');
        expect(full.find('[data-test="proof-line"]').text()).toContain('جاهز لتفعيل Require App Secret');

        const rejected = mount(MetaLastErrorCard, { props: { last: null, today, proof: { enabled: true, days: 7, calls: 10, proof: 10, proof_err: 2, percent: 100, ready_for_require: false } } });
        expect(rejected.find('[data-test="proof-line"]').text()).toContain('رفضته ميتا 2 مرة');
        expect(rejected.find('[data-test="proof-line"]').classes()).toContain('font-bold');
    });
});
