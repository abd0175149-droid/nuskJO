import { describe, expect, it } from 'vitest';
import { closed, urgency, windowLabel } from '@/composables/whatsapp/useWindowCountdown.js';

describe('windowLabel / urgency', () => {
    it('formats minutes as hours and minutes', () => {
        expect(windowLabel(0)).toBe('0د');
        expect(windowLabel(40)).toBe('40د');
        expect(windowLabel(61)).toBe('1س 1د');
        expect(windowLabel(120)).toBe('2س');
        expect(windowLabel(-5)).toBe('0د');
        expect(windowLabel(null)).toBe('0د');
    });

    it('grades urgency at six and two hours', () => {
        expect(urgency(361)).toBe('ok');
        expect(urgency(360)).toBe('warn');
        expect(urgency(121)).toBe('warn');
        expect(urgency(120)).toBe('danger');
        expect(urgency(null)).toBeNull();
        expect(closed(false)).toBe(true);
    });
});
