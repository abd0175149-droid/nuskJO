import { beforeEach, describe, expect, it, vi } from 'vitest';

// مسودة لكل محادثة (E32.1، شُحنت في Q.1): لا ينتقل نص عميل إلى آخر، وتُمسح بعد الإرسال وعند الخروج
async function fresh() {
    vi.resetModules();
    return import('@/composables/useDrafts.js');
}

beforeEach(() => {
    sessionStorage.clear();
    localStorage.clear();
});

describe('useDrafts', () => {
    it('keeps a separate draft for each conversation', async () => {
        const { useDrafts } = await fresh();
        const d = useDrafts();
        d.set(1, 'نص للعميل الأول');
        d.set(2, 'نص للثاني');
        expect(d.get(1)).toBe('نص للعميل الأول');
        expect(d.get(2)).toBe('نص للثاني');
        expect(d.get(3)).toBe('');
        d.clear(1);
        expect(d.get(1)).toBe('');
        expect(d.get(2)).toBe('نص للثاني');
    });

    it('mirrors to sessionStorage only, and logout clears it', async () => {
        const { useDrafts, clearStoredDrafts, DRAFTS_KEY } = await fresh();
        const d = useDrafts();
        d.set(5, 'مسودة');
        expect(JSON.parse(sessionStorage.getItem(DRAFTS_KEY))).toEqual({ 5: 'مسودة' });
        expect(localStorage.getItem(DRAFTS_KEY)).toBeNull();
        clearStoredDrafts();
        expect(sessionStorage.getItem(DRAFTS_KEY)).toBeNull();
        expect(d.get(5)).toBe('');
    });

    it('restores after a reload within its limits, and ignores a broken copy', async () => {
        sessionStorage.setItem('wa:drafts:v1', JSON.stringify({ 9: 'x'.repeat(5000) }));
        expect((await fresh()).useDrafts().get(9)).toHaveLength(4000);

        sessionStorage.setItem('wa:drafts:v1', '{broken');
        expect((await fresh()).useDrafts().get(9)).toBe('');
    });
});
