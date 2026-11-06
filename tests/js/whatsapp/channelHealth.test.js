import { describe, expect, it } from 'vitest';
import { ago, amman } from '@/utils/channelHealth.js';

describe('channelHealth utils', () => {
    it('formats ages', () => {
        const now = Date.parse('2026-10-16T10:00:00Z');
        expect(ago(null)).toBeNull();
        expect(ago('2026-10-16T09:59:30Z', now)).toBe('قبل أقل من دقيقة');
        expect(ago('2026-10-16T09:45:00Z', now)).toBe('قبل 15 د');
        expect(ago('2026-10-16T07:00:00Z', now)).toBe('قبل 3 س');
    });

    it('formats Amman times and dashes the missing ones', () => {
        expect(amman(null)).toBe('—');
        expect(amman('2026-10-20T09:00:00Z')).toContain('20/10/2026');
    });
});
