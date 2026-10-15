import { describe, expect, it } from 'vitest';
import { relativeTime } from '@/composables/whatsapp/useRelativeTime.js';

const now = Date.UTC(2026, 9, 15, 12, 0, 0);
const ago = (sec) => Math.floor(now / 1000) - sec;

describe('relativeTime', () => {
    it('speaks Arabic with Latin digits', () => {
        expect(relativeTime(ago(10), '', now)).toBe('الآن');
        expect(relativeTime(ago(18 * 60), '', now)).toBe('قبل 18 دقيقة');
        expect(relativeTime(ago(3 * 3600), '', now)).toBe('قبل 3 ساعات');
        expect(relativeTime(ago(26 * 3600), '', now)).toBe('أمس');
    });

    it('falls back to the server text without a time, and never shows the future', () => {
        expect(relativeTime(0, '5 minutes ago', now)).toBe('5 minutes ago');
        expect(relativeTime(null, '', now)).toBe('');
        expect(relativeTime(ago(-120), '', now)).toBe('الآن');
    });
});
