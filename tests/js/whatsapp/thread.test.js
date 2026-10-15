import { describe, expect, it } from 'vitest';
import { applyUpdates, lastId, mergeNewer, mergeOlder, needsReload } from '@/composables/whatsapp/useThread.js';

const m = (id, extra = {}) => ({ id, body: `#${id}`, status: 'sent', ...extra });

describe('thread merge', () => {
    it('appends only unseen newer messages in order and reports them', () => {
        const { messages, fresh } = mergeNewer([m(1), m(2)], [m(4), m(2), m(3)]);
        expect(messages.map((x) => x.id)).toEqual([1, 2, 3, 4]);
        expect(fresh.map((x) => x.id)).toEqual([3, 4]);
        expect(lastId(messages)).toBe(4);
        expect(lastId([])).toBe(0);
    });

    it('returns the same array when nothing is new', () => {
        const before = [m(1)];
        expect(mergeNewer(before, [m(1)]).messages).toBe(before);
    });

    it('prepends older messages without duplicates', () => {
        expect(mergeOlder([m(5), m(6)], [m(4), m(5), m(3)]).map((x) => x.id)).toEqual([3, 4, 5, 6]);
    });

    it('applies status updates in place and ignores unknown ids', () => {
        const list = [m(1), m(2)];
        applyUpdates(list, [{ id: 2, status: 'failed', error: 'x', media: { status: 'stored' } }, { id: 9, status: 'read' }]);
        expect(list[1]).toMatchObject({ status: 'failed', error: 'x', media: { status: 'stored' } });
        expect(list[0].status).toBe('sent');
    });

    it('reloads on a gap', () => {
        expect(needsReload({ gap: true })).toBe(true);
        expect(needsReload({ messages: [] })).toBe(false);
    });
});
