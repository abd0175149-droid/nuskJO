import { describe, expect, it } from 'vitest';
import { ago, healthState, inboxBadge } from '@/utils/channelHealth.js';

describe('channelHealth utils', () => {
    it('labels every state and falls back to unknown', () => {
        expect(healthState('LIMITED').label).toBe('قيد جزئي — ردود العملاء تعمل');
        expect(healthState('nonsense').label).toBe('لم تُفحص بعد');
    });

    it('gives the inbox a generic badge without any reason', () => {
        expect(inboxBadge({ health: 'BLOCKED', health_until: null }).text).toBe('⛔ ميتا تحجب الإرسال — أبلغ المدير.');
        expect(inboxBadge({ health: 'BLOCKED', health_until: '2026-10-20T09:00:00Z' }).text).toContain('حتى 20/10/2026');
        expect(inboxBadge({ health: 'TOKEN_INVALID', health_blocks: false }).text).toContain('قد يفشل الإرسال');
        expect(inboxBadge({ health: 'TOKEN_INVALID', health_blocks: true }).text).toContain('الإرسال متوقف');
        expect(inboxBadge({ health: 'LIMITED' })).toMatchObject({ tone: 'amber', small: true });
        expect(inboxBadge({ health: 'AVAILABLE' })).toBeNull();
        // الإيقاف اليدوي له شريطه الأعلى
        expect(inboxBadge({ health: 'BLOCKED', suspended: true })).toBeNull();
    });

    it('formats ages', () => {
        const now = Date.parse('2026-10-16T10:00:00Z');
        expect(ago(null)).toBeNull();
        expect(ago('2026-10-16T09:59:30Z', now)).toBe('قبل أقل من دقيقة');
        expect(ago('2026-10-16T09:45:00Z', now)).toBe('قبل 15 د');
        expect(ago('2026-10-16T07:00:00Z', now)).toBe('قبل 3 س');
    });
});
