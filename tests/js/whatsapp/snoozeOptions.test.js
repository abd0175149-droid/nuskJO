import { describe, expect, it } from 'vitest';
import { customBounds, maxSnoozeMinutes, snoozeOptions } from '@/composables/whatsapp/useSnoozeOptions.js';

describe('snoozeOptions', () => {
    it('offers nothing once the reply window is closed or too short', () => {
        expect(snoozeOptions(null)).toEqual([]);
        expect(snoozeOptions(20)).toEqual([]);
        expect(customBounds(null)).toBeNull();
    });

    it('disables every option past the window end minus 15 minutes', () => {
        const opts = snoozeOptions(120); // أبعد موعد بعد 105 دقائق
        expect(maxSnoozeMinutes(120)).toBe(105);
        expect(opts.find((o) => o.key === 'h1').disabled).toBe(false);
        const h3 = opts.find((o) => o.key === 'h3');
        expect(h3.disabled).toBe(true);
        expect(h3.hint).toBe('لا يمكن التأجيل بعد إغلاق النافذة');
        expect(opts.find((o) => o.key === 'before_close').minutes).toBe(90);
    });

    it('keeps the window-close option within bounds for long windows', () => {
        const opts = snoozeOptions(23 * 60);
        expect(opts.every((o) => !o.disabled)).toBe(true);
        expect(opts.find((o) => o.key === 'before_close').minutes).toBe(23 * 60 - 30);
    });

    it('bounds the custom time between 10 minutes and the window limit', () => {
        const now = new Date('2026-10-16T10:00:00Z');
        const b = customBounds(120, now);
        const local = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        expect(b.min).toBe(local(new Date('2026-10-16T10:10:00Z')));
        expect(b.max).toBe(local(new Date('2026-10-16T11:45:00Z')));
    });
});
