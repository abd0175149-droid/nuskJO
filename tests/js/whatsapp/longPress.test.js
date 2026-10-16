import { afterEach, describe, expect, it, vi } from 'vitest';
import { LONG_PRESS_MS, useLongPress } from '@/composables/whatsapp/useLongPress.js';

// الضغط المطوّل (E33): 500ms باللمس يفتح قائمة الرسالة، والتحريك أو الرفع المبكر يلغيه، والفأرة لها زر ⋯
describe('useLongPress', () => {
    afterEach(() => vi.useRealTimers());
    const touch = (over = {}) => ({ pointerType: 'touch', button: 0, clientX: 10, clientY: 10, preventDefault: vi.fn(), ...over });

    it('fires once after 500ms of a still touch', () => {
        vi.useFakeTimers();
        const fn = vi.fn();
        const { handlers } = useLongPress(fn);
        handlers.pointerdown(touch());
        vi.advanceTimersByTime(LONG_PRESS_MS - 1);
        expect(fn).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(fn).toHaveBeenCalledTimes(1);
        expect(LONG_PRESS_MS).toBe(500);
    });

    it('is cancelled by moving, lifting early, or a mouse', () => {
        vi.useFakeTimers();
        const fn = vi.fn();
        const { handlers } = useLongPress(fn);
        handlers.pointerdown(touch());
        handlers.pointermove(touch({ clientX: 40 }));
        vi.advanceTimersByTime(600);
        handlers.pointerdown(touch());
        vi.advanceTimersByTime(200);
        handlers.pointerup();
        vi.advanceTimersByTime(600);
        handlers.pointerdown(touch({ pointerType: 'mouse' }));
        vi.advanceTimersByTime(600);
        expect(fn).not.toHaveBeenCalled();
    });

    it('a small wobble does not cancel, and the browser menu is suppressed after a press', () => {
        vi.useFakeTimers();
        const fn = vi.fn();
        const { handlers } = useLongPress(fn);
        handlers.pointerdown(touch());
        handlers.pointermove(touch({ clientX: 14, clientY: 12 }));
        vi.advanceTimersByTime(500);
        expect(fn).toHaveBeenCalledTimes(1);
        const menu = { preventDefault: vi.fn() };
        handlers.contextmenu(menu);
        expect(menu.preventDefault).toHaveBeenCalled();
    });
});
