import { describe, expect, it } from 'vitest';
import { isFinePointer, sendOnEnter } from '@/composables/whatsapp/useInputMode.js';

const key = (extra = {}) => ({ key: 'Enter', shiftKey: false, isComposing: false, ...extra });

describe('input mode', () => {
    it('detects a fine pointer from the media query', () => {
        expect(isFinePointer(() => ({ matches: true }))).toBe(true);
        expect(isFinePointer(() => ({ matches: false }))).toBe(false);
        expect(isFinePointer(undefined)).toBe(false);
    });

    it('sends on Enter only on desktop, never with Shift or while composing', () => {
        expect(sendOnEnter(key(), true)).toBe(true);
        expect(sendOnEnter(key(), false)).toBe(false);
        expect(sendOnEnter(key({ shiftKey: true }), true)).toBe(false);
        expect(sendOnEnter(key({ isComposing: true }), true)).toBe(false);
        expect(sendOnEnter(key({ key: 'a' }), true)).toBe(false);
    });
});
