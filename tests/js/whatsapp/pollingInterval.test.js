import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPoller } from '@/composables/whatsapp/usePolling.js';

const doc = { visibilityState: 'visible', addEventListener() {}, removeEventListener() {} };

// الاستطلاع احتياط البث اللحظي (E28): 60 ثانية والمقبس متصل، و15 حين ينقطع
describe('createPoller.setInterval', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('moves the next tick to the new interval at once', async () => {
        const tick = vi.fn(async () => {});
        const p = createPoller(tick, { interval: 15000, doc });
        p.start();
        p.setInterval(60000);
        expect(p.interval()).toBe(60000);
        await vi.advanceTimersByTimeAsync(59000);
        expect(tick).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1000);
        expect(tick).toHaveBeenCalledTimes(1);

        p.setInterval(15000);
        await vi.advanceTimersByTimeAsync(15000);
        expect(tick).toHaveBeenCalledTimes(2);
        p.stop();
    });

    it('ignores invalid values and does not start a stopped poller', async () => {
        const tick = vi.fn(async () => {});
        const p = createPoller(tick, { interval: 15000, doc });
        p.setInterval(0);
        p.setInterval(-5);
        expect(p.interval()).toBe(15000);
        p.setInterval(60000);
        await vi.advanceTimersByTimeAsync(120000);
        expect(tick).not.toHaveBeenCalled();
    });
});
