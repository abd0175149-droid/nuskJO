import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import IntegrationsHealthLine from '@/Components/WhatsApp/IntegrationsHealthLine.vue';
import OneTimeSecretModal from '@/Components/WhatsApp/OneTimeSecretModal.vue';

// سطر التكاملات في بطاقة الصحة (E40.6): أعداد فقط، وأحمر عند الفاشل أو الموقوف أو معلّق أقدم من ساعة
const line = (extra = {}) => ({ enabled: true, endpoints: 2, active: 2, paused: 0, pending: 0, oldest_pending_minutes: null, dead_24h: 0, ...extra });

describe('IntegrationsHealthLine', () => {
    it('is hidden before use and says when the flag is off', () => {
        expect(mount(IntegrationsHealthLine, { props: { line: null } }).find('[data-test="integrations-health"]').exists()).toBe(false);
        expect(mount(IntegrationsHealthLine, { props: { line: line({ enabled: false }) } }).text()).toContain('مطفأة');
    });

    it('shows counts and turns red on failures, paused endpoints or an old backlog', () => {
        const calm = mount(IntegrationsHealthLine, { props: { line: line({ pending: 3, oldest_pending_minutes: 5 }) } });
        expect(calm.text()).toContain('معلّق 3');
        expect(calm.text()).toContain('5 د');
        expect(calm.find('p').classes()).not.toContain('text-red-700');

        for (const extra of [{ dead_24h: 1 }, { paused: 1, active: 1 }, { pending: 1, oldest_pending_minutes: 90 }]) {
            expect(mount(IntegrationsHealthLine, { props: { line: line(extra) } }).find('p').classes()).toContain('text-red-700');
        }
        expect(mount(IntegrationsHealthLine, { props: { line: line({ pending: 1, oldest_pending_minutes: 125 }) } }).text()).toContain('2 س');
    });
});

describe('OneTimeSecretModal', () => {
    it('keeps the Meta verify-token steps by default and accepts other steps', () => {
        const meta = mount(OneTimeSecretModal, { props: { token: 'abc' } });
        expect(meta.text()).toContain('Verify and save');
        expect(meta.text()).toContain('نسخته ولصقته في لوحة ميتا');

        const other = mount(OneTimeSecretModal, { props: { token: 'whsec_x', title: 'سر التوقيع', doneLabel: 'حفظته' }, slots: { default: '<p>خطوات أخرى</p>' } });
        expect(other.text()).toContain('سر التوقيع');
        expect(other.text()).toContain('خطوات أخرى');
        expect(other.text()).not.toContain('Verify and save');
    });
});
