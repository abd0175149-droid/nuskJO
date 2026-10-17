import { describe, expect, it } from 'vitest';
import { formatPhone } from '@/composables/whatsapp/usePhoneFormat.js';

describe('formatPhone', () => {
    it('groups Jordanian and other numbers by their country code', () => {
        expect(formatPhone('962791234567')).toBe('+962 79 123 4567');
        expect(formatPhone('+962 79 123 4567')).toBe('+962 79 123 4567');
        expect(formatPhone('966501234567')).toBe('+966 50 123 4567');
        expect(formatPhone('16505551234')).toBe('+1 650 555 1234');
    });

    it('keeps short or unknown values readable and empty values empty', () => {
        expect(formatPhone('')).toBe('');
        expect(formatPhone(null)).toBe('');
        expect(formatPhone('12345')).toBe('+12345');
        expect(formatPhone('5551234567890')).toBe('+5551234567890');
    });
});
