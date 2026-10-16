import { describe, expect, it, vi } from 'vitest';
import { hasDirtyForm, isChunkLoadError, reloadOnce, RELOAD_GUARD_MS, setDirtyForm } from '@/utils/reloadOnce.js';

function fakeWindow({ storage = 'ok' } = {}) {
    const data = {};
    const sessionStorage = storage === 'blocked'
        ? { getItem() { throw new DOMException('denied', 'SecurityError'); }, setItem() { throw new DOMException('denied', 'SecurityError'); } }
        : { getItem: (k) => data[k] ?? null, setItem: (k, v) => { data[k] = v; } };
    return { sessionStorage, name: '', location: { reload: vi.fn() } };
}

// إعادة تحميل واحدة بعد النشر (E28): لا حلقة إعادة تحميل
describe('reloadOnce', () => {
    it('reloads once within 30 seconds', () => {
        const win = fakeWindow();
        expect(reloadOnce('page', { win, now: 1000 })).toBe(true);
        expect(reloadOnce('page', { win, now: 1000 + RELOAD_GUARD_MS - 1 })).toBe(false);
        expect(win.location.reload).toHaveBeenCalledTimes(1);
        expect(reloadOnce('chunk', { win, now: 1000 + RELOAD_GUARD_MS + 1 })).toBe(true);
        expect(win.location.reload).toHaveBeenCalledTimes(2);
    });

    it('survives blocked storage (SecurityError) with a single reload', () => {
        const win = fakeWindow({ storage: 'blocked' });
        expect(() => reloadOnce('page', { win, now: 5000 })).not.toThrow();
        expect(reloadOnce('page', { win, now: 6000 })).toBe(false);
        expect(win.location.reload).toHaveBeenCalledTimes(1);
    });

    it('keeps the guard across the reload itself when storage is blocked (window.name)', () => {
        const before = fakeWindow({ storage: 'blocked' });
        reloadOnce('page', { win: before, now: 5000 });
        // بعد إعادة التحميل: ذاكرة جديدة والاسم باقٍ في التبويب
        const after = { ...fakeWindow({ storage: 'blocked' }), name: before.name };
        expect(reloadOnce('page', { win: after, now: 9000 })).toBe(false);
        expect(after.location.reload).not.toHaveBeenCalled();
    });

    it('never stores anything personal', () => {
        const win = fakeWindow();
        reloadOnce('page', { win, now: 1234 });
        expect(win.sessionStorage.getItem('nuskjo:reload-at')).toBe('1234');
    });

    it('tracks unsaved forms', () => {
        const win = fakeWindow();
        expect(hasDirtyForm(win)).toBe(false);
        setDirtyForm('m1', true);
        expect(hasDirtyForm(win)).toBe(true);
        setDirtyForm('m1', false);
        expect(hasDirtyForm(win)).toBe(false);
        win.__nuskjoDirtyForm = true;
        expect(hasDirtyForm(win)).toBe(true);
    });

    it('tells a missing chunk from a page bug', () => {
        expect(isChunkLoadError(new TypeError('Failed to fetch dynamically imported module: https://x/build/assets/Inbox-1.js'))).toBe(true);
        expect(isChunkLoadError(new TypeError('Importing a module script failed.'))).toBe(true);
        expect(isChunkLoadError(new TypeError("Cannot read properties of undefined (reading 'id')"))).toBe(false);
    });
});
