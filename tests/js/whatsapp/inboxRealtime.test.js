import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createInboxRealtime, FALLBACK_CONNECTED_MS, FALLBACK_DOWN_MS, LIST_DEBOUNCE_MS } from '@/composables/whatsapp/useInboxRealtime.js';
import { resetConnectionStatus, useConnectionStatus } from '@/composables/useConnectionStatus.js';

// Echo وهمي: قناة user.{id} واحدة واتصال pusher بحالاته
function fakeEcho(state = 'connected') {
    const listeners = {};
    const sub = {};
    const states = [];
    const channel = {
        listen: vi.fn((e, f) => { listeners[e] = f; return channel; }),
        stopListening: vi.fn((e, f) => { if (listeners[e] === f) delete listeners[e]; return channel; }),
        subscription: { bind: vi.fn((e, f) => { sub[e] = f; }), unbind: vi.fn((e) => { delete sub[e]; }) },
    };
    const connection = {
        state,
        bind: vi.fn((e, f) => { if (e === 'state_change') states.push(f); }),
        unbind: vi.fn((e, f) => { const i = states.indexOf(f); if (i >= 0) states.splice(i, 1); }),
    };
    return {
        echo: { private: vi.fn(() => channel), connector: { pusher: { connection } } },
        channel,
        emit: (data) => listeners['.wa.changed']?.(data),
        change: (previous, current) => { connection.state = current; states.forEach((f) => f({ previous, current })); },
        subscriptionError: () => sub['pusher:subscription_error']?.({ status: 403 }),
        listeners,
        states,
    };
}

function setup({ active = 5, enabled = true, state = 'connected', echo } = {}) {
    const fake = fakeEcho(state);
    const deps = {
        activeId: vi.fn(() => active),
        reloadList: vi.fn(),
        pollThread: vi.fn(),
        poller: { setInterval: vi.fn() },
    };
    const rt = createInboxRealtime({ ...deps, enabled, userId: 9, echo: echo ?? (() => fake.echo) });
    return { fake, rt, ...deps };
}

describe('useInboxRealtime', () => {
    beforeEach(() => { vi.useFakeTimers(); resetConnectionStatus(); });
    afterEach(() => vi.useRealTimers());

    it('listens on the user channel the layout already holds', () => {
        const { fake, rt } = setup();
        expect(rt.start()).toBe(true);
        expect(fake.echo.private).toHaveBeenCalledWith('user.9');
        expect(fake.channel.listen).toHaveBeenCalledWith('.wa.changed', expect.any(Function));
    });

    it('a status event for the open conversation polls the thread once and leaves the list alone', async () => {
        const { fake, rt, reloadList, pollThread } = setup({ active: 5 });
        rt.start();
        fake.emit({ c: 5, k: 'status' });
        await vi.advanceTimersByTimeAsync(LIST_DEBOUNCE_MS + 50);
        expect(pollThread).toHaveBeenCalledTimes(1);
        expect(reloadList).not.toHaveBeenCalled();

        fake.emit({ c: 6, k: 'status' });
        await vi.advanceTimersByTimeAsync(LIST_DEBOUNCE_MS + 50);
        expect(pollThread).toHaveBeenCalledTimes(1);
        expect(reloadList).not.toHaveBeenCalled();
    });

    it('five message events reload the list once after the debounce', async () => {
        const { fake, rt, reloadList, pollThread } = setup({ active: 2 });
        rt.start();
        for (let i = 0; i < 5; i++) fake.emit({ c: 7, k: 'message' });
        expect(reloadList).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(LIST_DEBOUNCE_MS + 50);
        expect(reloadList).toHaveBeenCalledTimes(1);
        expect(pollThread).not.toHaveBeenCalled();
    });

    it('a message or meta event for the open conversation also polls its thread', async () => {
        const { fake, rt, reloadList, pollThread } = setup({ active: 7 });
        rt.start();
        fake.emit({ c: 7, k: 'meta' });
        await vi.advanceTimersByTimeAsync(LIST_DEBOUNCE_MS + 50);
        expect(pollThread).toHaveBeenCalledTimes(1);
        expect(reloadList).toHaveBeenCalledTimes(1);
    });

    it('coming back from unavailable reloads the list and the thread, and switches the fallback poll', async () => {
        const { fake, rt, reloadList, pollThread, poller } = setup({ state: 'connected' });
        rt.start();
        expect(poller.setInterval).toHaveBeenLastCalledWith(FALLBACK_CONNECTED_MS);
        expect(reloadList).not.toHaveBeenCalled();
        expect(useConnectionStatus().state.realtime).toBe('connected');

        fake.change('connected', 'connecting');
        fake.change('connecting', 'unavailable');
        expect(poller.setInterval).toHaveBeenLastCalledWith(FALLBACK_DOWN_MS);
        expect(useConnectionStatus().state.realtime).toBe('unavailable');

        fake.change('unavailable', 'connected');
        expect(reloadList).toHaveBeenCalledTimes(1);
        expect(pollThread).toHaveBeenCalledTimes(1);
        expect(poller.setInterval).toHaveBeenLastCalledWith(FALLBACK_CONNECTED_MS);
    });

    it('a socket that connects after the page loaded fills the gap once', () => {
        const { fake, rt, reloadList, poller } = setup({ state: 'connecting' });
        rt.start();
        expect(poller.setInterval).not.toHaveBeenCalledWith(FALLBACK_CONNECTED_MS);
        fake.change('connecting', 'connected');
        expect(reloadList).toHaveBeenCalledTimes(1);
    });

    it('a refused subscription falls back to the fast poll', () => {
        const { fake, rt, poller } = setup();
        rt.start();
        fake.subscriptionError();
        expect(poller.setInterval).toHaveBeenLastCalledWith(FALLBACK_DOWN_MS);
        expect(useConnectionStatus().state.realtime).toBe('failed');
    });

    it('with the flag off or without Echo it only polls every 15 seconds', () => {
        const off = setup({ enabled: false });
        expect(off.rt.start()).toBe(false);
        expect(off.fake.echo.private).not.toHaveBeenCalled();
        expect(off.poller.setInterval).toHaveBeenLastCalledWith(FALLBACK_DOWN_MS);

        const none = setup({ echo: () => undefined });
        expect(none.rt.start()).toBe(false);
        expect(none.poller.setInterval).toHaveBeenLastCalledWith(FALLBACK_DOWN_MS);
        expect(useConnectionStatus().state.realtime).toBe('disabled');
    });

    it('stopping removes its own listener only (the layout keeps the channel) and restores 15 seconds', async () => {
        const { fake, rt, reloadList, poller } = setup();
        rt.start();
        fake.emit({ c: 3, k: 'message' });
        rt.stop();
        await vi.advanceTimersByTimeAsync(LIST_DEBOUNCE_MS + 50);
        expect(reloadList).not.toHaveBeenCalled();
        expect(fake.channel.stopListening).toHaveBeenCalledWith('.wa.changed', expect.any(Function));
        expect(fake.listeners['.wa.changed']).toBeUndefined();
        expect(fake.states).toHaveLength(0);
        expect(fake.echo.leave).toBeUndefined();
        expect(poller.setInterval).toHaveBeenLastCalledWith(FALLBACK_DOWN_MS);
    });

    it('never marks anything read', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} });
        const { fake, rt } = setup({ active: 5 });
        rt.start();
        for (const k of ['message', 'status', 'meta']) fake.emit({ c: 5, k });
        fake.change('connected', 'unavailable');
        fake.change('unavailable', 'connected');
        await vi.advanceTimersByTimeAsync(LIST_DEBOUNCE_MS + 50);
        expect(post).not.toHaveBeenCalled();
        post.mockRestore();
    });
});
