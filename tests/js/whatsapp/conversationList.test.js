import { describe, expect, it } from 'vitest';
import { appendPage, cursorOf, lastTypeIcon, mergeFirstPage } from '@/composables/whatsapp/useConversationList.js';

const row = (id, sort_ts) => ({ id, sort_ts });

describe('conversation list paging', () => {
    it('replaces the first page and keeps what was loaded after it', () => {
        const loaded = [row(9, 900), row(8, 800), row(7, 700), row(6, 600), row(5, 500)];
        // 5 كتب الآن فصعد إلى الأعلى، والصفحة الأولى بثلاثة صفوف
        const page = [row(5, 1000), row(9, 900), row(8, 800)];
        expect(mergeFirstPage(loaded, page, true).map((c) => c.id)).toEqual([5, 9, 8, 7, 6]);
    });

    it('a complete first page replaces everything', () => {
        expect(mergeFirstPage([row(1, 1), row(2, 2)], [row(3, 3)], false).map((c) => c.id)).toEqual([3]);
    });

    it('appends a later page without duplicates and builds the cursor from the last row', () => {
        const list = appendPage([row(9, 900), row(8, 800)], [row(8, 800), row(7, 700)]);
        expect(list.map((c) => c.id)).toEqual([9, 8, 7]);
        expect(cursorOf(list)).toBe('700:7');
        expect(cursorOf([])).toBeNull();
        expect(cursorOf([{ id: 4 }])).toBe('0:4');
    });

    it('maps the last message type to an icon', () => {
        expect(lastTypeIcon('image')).toBe('📷');
        expect(lastTypeIcon('text')).toBe('');
        expect(lastTypeIcon(null)).toBe('');
    });
});
