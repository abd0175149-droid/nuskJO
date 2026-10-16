import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SecurityScanLine from '@/Components/WhatsApp/SecurityScanLine.vue';

const now = Date.parse('2026-10-18T10:00:00+03:00');
const scan = (extra = {}) => ({ scanner: 'trivy', high: 3, critical: 0, at: '2026-10-18T04:20:00+03:00', previous_high: 3, ...extra });

describe('SecurityScanLine', () => {
    it('shows the date and the counts only', () => {
        const w = mount(SecurityScanLine, { props: { scan: scan(), now } });
        expect(w.text()).toContain('آخر فحص للصورة');
        expect(w.text()).toContain('2026-10-18');
        expect(w.text()).toContain('حرجة 0');
        expect(w.text()).toContain('عالية 3');
        expect(w.text()).not.toContain('كانت');
        expect(w.find('[data-test="security-scan"]').classes()).not.toContain('text-red-700');
    });

    it('marks critical findings red and a rise in high findings', () => {
        const w = mount(SecurityScanLine, { props: { scan: scan({ critical: 1, high: 5, previous_high: 2 }), now } });
        expect(w.find('[data-test="security-scan"]').classes()).toContain('text-red-700');
        expect(w.text()).toContain('(كانت 2)');
    });

    it('says when no scan was recorded and when the weekly scan stopped', () => {
        expect(mount(SecurityScanLine, { props: { scan: null, now } }).text()).toContain('لم يُسجَّل فحص بعد');
        const old = mount(SecurityScanLine, { props: { scan: scan({ at: '2026-09-30T04:20:00+03:00' }), now } });
        expect(old.text()).toContain('أقدم من أسبوعين');
    });
});
