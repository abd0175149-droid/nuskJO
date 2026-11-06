import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import WebhookIntakePanel from '@/Components/WhatsApp/WebhookIntakePanel.vue';

const summary = (over = {}) => ({
    log: { on: true, mode: 'all', table: true },
    last24: { processed: 41, failed: 2, dead: 0, malformed: 1, rejected: 1, pending: 0 },
    dead_open: 0,
    oldest_body_hours: 5,
    signatures: { hour: 0, day: 3 },
    limits: { max_bytes: 4194304, body_hours: 72, meta_days: 14, max_attempts: 3 },
    warnings: [],
    ...over,
});

describe('WebhookIntakePanel', () => {
    it('shows counts, signatures and limits only', () => {
        const w = mount(WebhookIntakePanel, { props: { summary: summary() } });
        const text = w.text();
        expect(text).toContain('الحفظ قبل الإقرار: يعمل');
        expect(text).toContain('عولج');
        expect(text).toContain('41');
        expect(text).toContain('يومان 3');
        expect(text).toContain('الحد 4MB');
        expect(text).toContain('أقدم جسم محفوظ: 5 ساعة');
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('raises a red warning for dead rows, rejections and an overdue body', () => {
        const w = mount(WebhookIntakePanel, { props: { summary: summary({ warnings: ['dead', 'rejected', 'retention'], dead_open: 2 }) } });
        const alert = w.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.findAll('li')).toHaveLength(3);
        expect(alert.text()).toContain('whatsapp:replay-webhooks');
        expect(w.text()).toContain('ينتظر المعالجة اليدوية: 2');
    });
});
