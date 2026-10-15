import { describe, expect, it } from 'vitest';
import { AVATAR_COLORS, colorFor, initials } from '@/composables/whatsapp/useInitials.js';

describe('initials', () => {
    it('takes the first letters of the first two words', () => {
        expect(initials('عبد الرحمن الأحمد')).toBe('عا');
        expect(initials('Ahmad Saleh')).toBe('AS');
        expect(initials('سامي')).toBe('س');
        expect(initials('ahmad')).toBe('AH');
    });

    it('ignores diacritics, symbols, a lone «ال» and gives # without letters', () => {
        expect(initials('مُحَمَّد ال عُمَر')).toBe('مع');
        expect(initials('👤 ✨ ليلى')).toBe('ل');
        expect(initials('+962 79 123 4567')).toBe('#');
        expect(initials('')).toBe('#');
        expect(initials(null)).toBe('#');
    });
});

describe('colorFor', () => {
    it('is stable for a key and spread over the eight colours', () => {
        expect(colorFor(42)).toBe(colorFor(42));
        expect(colorFor('42')).toBe(colorFor(42));
        const used = new Set(Array.from({ length: 200 }, (_, i) => colorFor(i)));
        expect(used.size).toBe(AVATAR_COLORS.length);
        for (const c of used) expect(AVATAR_COLORS).toContain(c);
    });
});
