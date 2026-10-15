import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPoller } from '@/composables/whatsapp/usePolling.js';

function fakeDoc(state = 'visible') {
    const listeners = {};
    return {
        visibilityState: state,
        addEventListener: (n, f) => { listeners[n] = f; },
        removeEventListener: (n) => { delete listeners[n]; },
        fire: (n) => listeners[n]?.(),
    };
}

describe('createPoller', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('ticks every interval and never runs two requests at once', async () => {
        let resolve;
        const tick = vi.fn(() => new Promise((r) => { resolve = r; }));
        const p = createPoller(tick, { interval: 1000, doc: fakeDoc() });
        p.start();
        await vi.advanceTimersByTimeAsync(1000);
        expect(tick).toHaveBeenCalledTimes(1);
        p.run();
        expect(tick).toHaveBeenCalledTimes(1);
        resolve();
        p.stop();
    });

    it('pauses while hidden and runs at once when visible again', async () => {
        const doc = fakeDoc('hidden');
        const tick = vi.fn(async () => {});
        const p = createPoller(tick, { interval: 1000, doc });
        p.start();
        await vi.advanceTimersByTimeAsync(3000);
        expect(tick).not.toHaveBeenCalled();
        doc.visibilityState = 'visible';
        doc.fire('visibilitychange');
        await vi.advanceTimersByTimeAsync(0);
        expect(tick).toHaveBeenCalledTimes(1);
        p.stop();
    });

    it('backs off after repeated failures and resets on success', async () => {
        let fail = true;
        const onError = vi.fn();
        const p = createPoller(async () => { if (fail) throw new Error('down'); }, { interval: 1000, maxInterval: 8000, doc: fakeDoc(), onError });
        await p.run();
        await p.run();
        expect(p.failures()).toBe(2);
        expect(p.nextDelay()).toBe(4000);
        expect(onError).toHaveBeenLastCalledWith(expect.any(Error), 2);
        fail = false;
        await p.run();
        expect(p.failures()).toBe(0);
        expect(p.nextDelay()).toBe(1000);
    });
});
