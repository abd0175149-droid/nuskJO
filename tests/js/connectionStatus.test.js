import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ConnectionBanner from '@/Components/Layout/ConnectionBanner.vue';
import PageErrorCard from '@/Components/Layout/PageErrorCard.vue';
import { PROBE_MS, resetConnectionStatus, useConnectionStatus } from '@/composables/useConnectionStatus.js';
import { installConnectionGuard, recoverPageLoad, setResilience } from '@/utils/connectionGuard.js';

// موجّه Inertia وaxios وهميان: نلتقط المعالجات كما يسجّلها التطبيق
function fakes() {
    const handlers = {};
    const router = { on: (name, fn) => { handlers[name] = fn; return () => {}; } };
    const interceptors = {};
    const axios = { interceptors: { response: { use: (ok, fail) => { interceptors.ok = ok; interceptors.fail = fail; } } } };
    const app = { config: {} };
    const listeners = {};
    const win = { addEventListener: (n, f) => { listeners[n] = f; }, sessionStorage: { getItem: () => null, setItem() {} }, name: '', location: { reload: vi.fn() } };
    installConnectionGuard({ router, axios, app, win });
    return { handlers, interceptors, app, listeners, win };
}

const cloudflare = (status) => ({ response: { status, data: '<html><title>Cloudflare 502 Bad gateway</title></html>' } });

describe('connection banner (E28)', () => {
    let f;
    beforeEach(() => {
        vi.useFakeTimers();
        resetConnectionStatus({ fetch: vi.fn(async () => ({ status: 200 })) });
        f = fakes();
        setResilience(true);
    });
    afterEach(() => {
        vi.useRealTimers();
        setResilience(false);
    });

    it('three 502s from polling and Inertia give one banner without the raw page, and the first success hides it', async () => {
        const banner = mount(ConnectionBanner);

        expect(f.handlers.httpException({ detail: { response: { status: 502, data: '<html>Cloudflare</html>' } } })).toBe(false);
        await expect(f.interceptors.fail(cloudflare(502))).rejects.toBeTruthy();
        await expect(f.interceptors.fail(cloudflare(502))).rejects.toBeTruthy();
        await nextTick();

        expect(banner.findAll('[data-test="server-down"]')).toHaveLength(1);
        expect(banner.text()).toContain('النظام يُعاد تشغيله أو غير متاح — سنحاول تلقائياً');
        expect(banner.html()).not.toContain('Cloudflare');

        f.interceptors.ok({ status: 200 });
        await nextTick();
        expect(banner.find('[data-test="server-down"]').exists()).toBe(false);
    });

    it('a network error or a gateway timeout shows the banner; an ordinary error does not', async () => {
        const banner = mount(ConnectionBanner);
        await expect(f.interceptors.fail({ message: 'Network Error' })).rejects.toBeTruthy();
        await nextTick();
        expect(banner.find('[data-test="server-down"]').exists()).toBe(true);

        f.interceptors.ok({});
        await expect(f.interceptors.fail({ response: { status: 422, data: { error: 'خطأ' } } })).rejects.toBeTruthy();
        await expect(f.interceptors.fail({ code: 'ERR_CANCELED' })).rejects.toBeTruthy();
        expect(f.handlers.httpException({ detail: { response: { status: 403 } } })).toBeUndefined();
        await nextTick();
        expect(banner.find('[data-test="server-down"]').exists()).toBe(false);
    });

    it('checks /up while down and clears itself when the server answers', async () => {
        const fetch = vi.fn(async () => ({ status: 502 }));
        resetConnectionStatus({ fetch });
        f.handlers.networkError({ detail: { error: new Error('Network Error') } });
        expect(useConnectionStatus().state.serverDown).toBe(true);

        await vi.advanceTimersByTimeAsync(PROBE_MS);
        expect(fetch).toHaveBeenCalledWith('/up', expect.objectContaining({ cache: 'no-store' }));
        expect(useConnectionStatus().state.serverDown).toBe(true);

        fetch.mockResolvedValue({ status: 200 });
        await vi.advanceTimersByTimeAsync(PROBE_MS);
        expect(useConnectionStatus().state.serverDown).toBe(false);
    });

    it('with the flag off nothing changes', async () => {
        setResilience(false);
        const banner = mount(ConnectionBanner);
        expect(f.handlers.httpException({ detail: { response: { status: 502 } } })).toBeUndefined();
        await expect(f.interceptors.fail(cloudflare(504))).rejects.toBeTruthy();
        await nextTick();
        expect(banner.find('[data-test="server-down"]').exists()).toBe(false);
    });

    it('the flag follows the shared page props on every navigation', () => {
        setResilience(false);
        f.handlers.navigate({ detail: { page: { props: { features: { resilience: true } } } } });
        expect(f.handlers.httpException({ detail: { response: { status: 524 } } })).toBe(false);
    });

    it('a page error shows the card instead of a blank page, without the error text', async () => {
        const card = mount(PageErrorCard);
        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

        f.app.config.errorHandler(Object.assign(new Error('boom'), { isAxiosError: true }));
        await nextTick();
        expect(card.find('[data-test="page-error"]').exists()).toBe(false);

        f.app.config.errorHandler(new TypeError("Cannot read properties of undefined (reading 'secret')"));
        await nextTick();
        expect(card.find('[data-test="page-error"]').text()).toContain('حدث خطأ في هذه الصفحة — أعد التحميل');
        expect(card.html()).not.toContain('secret');

        f.handlers.navigate({ detail: { page: { props: { features: { resilience: true } } } } });
        await nextTick();
        expect(card.find('[data-test="page-error"]').exists()).toBe(false);
        spy.mockRestore();
    });

    it('a missing chunk reloads once, or offers the update over an unsaved form', async () => {
        const banner = mount(ConnectionBanner);
        const event = { preventDefault: vi.fn() };
        f.listeners['vite:preloadError'](event);
        expect(f.win.location.reload).toHaveBeenCalledTimes(1);

        f.win.__nuskjoDirtyForm = true;
        const dirty = { preventDefault: vi.fn() };
        f.listeners['vite:preloadError'](dirty);
        expect(dirty.preventDefault).toHaveBeenCalled();
        expect(f.win.location.reload).toHaveBeenCalledTimes(1);
        await nextTick();
        expect(banner.find('[data-test="update-available"]').text()).toContain('تحديث متاح');
    });

    it('a page whose file vanished after a deploy reloads once', () => {
        const win = { sessionStorage: { getItem: () => null, setItem() {} }, name: '', location: { reload: vi.fn() } };
        expect(recoverPageLoad(new TypeError('Failed to fetch dynamically imported module: /build/assets/Inbox-x.js'), { win })).toBe(true);
        expect(recoverPageLoad(new TypeError('x is undefined'), { win })).toBe(false);
        expect(win.location.reload).toHaveBeenCalledTimes(1);
    });
});
