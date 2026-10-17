import { describe, expect, it } from 'vitest';
import { isNearBottom, preserveOnPrepend, scrollToBottom } from '@/composables/whatsapp/useStickToBottom.js';

const box = (scrollHeight, scrollTop, clientHeight = 400) => ({ scrollHeight, scrollTop, clientHeight });

describe('stick to bottom', () => {
    it('is near the bottom within 150px', () => {
        expect(isNearBottom(box(1000, 500))).toBe(true);    // 100 من الأسفل
        expect(isNearBottom(box(1000, 400))).toBe(false);   // 200
        expect(isNearBottom(null)).toBe(true);
    });

    it('scrolls to the bottom', () => {
        const el = box(1000, 0);
        scrollToBottom(el);
        expect(el.scrollTop).toBe(1000);
    });

    it('keeps the reading position when older messages are prepended', async () => {
        const el = box(1000, 30);
        await preserveOnPrepend(el, () => { el.scrollHeight = 1600; }, async () => {});
        expect(el.scrollTop).toBe(630);
    });
});
