import { describe, expect, it } from 'vitest';
import { createSendKeys, uuid } from '@/composables/whatsapp/useSendKey.js';

describe('useSendKey', () => {
    it('makes RFC 4122 v4 keys the server accepts', () => {
        expect(uuid()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    });

    it('keeps the key for a retry of the same draft and renews it after success or a new text', () => {
        let n = 0;
        const keys = createSendKeys(() => `k${++n}`);

        expect(keys.keyFor(1, 'أهلاً ')).toBe('k1');
        expect(keys.keyFor(1, 'أهلاً')).toBe('k1');
        expect(keys.keyFor(1, 'أهلاً بك')).toBe('k2');
        keys.done(1);
        expect(keys.keyFor(1, 'أهلاً بك')).toBe('k3');
    });

    it('gives each conversation its own key and rotates on mismatch', () => {
        let n = 0;
        const keys = createSendKeys(() => `k${++n}`);

        expect(keys.keyFor(1, 'نص')).toBe('k1');
        expect(keys.keyFor(2, 'نص')).toBe('k2');
        keys.rotate(1);
        expect(keys.keyFor(1, 'نص')).toBe('k3');
        expect(keys.keyFor(2, 'نص')).toBe('k2');
    });
});
