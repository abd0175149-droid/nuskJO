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

    it('applies the status fields of E18, E19 and E21 and drops the ones the update no longer carries', () => {
        const list = [m(1, { status: 'failed', error: 'تعذّر التسليم', error_code: 'RECIPIENT_UNREACHABLE', category: 'caller', action: 'اطلب…', meta_code: 131026, multi_fail: false })];
        applyUpdates(list, [{ id: 1, status: 'delivered', error: null, status_at: '14:05', multi_fail: true }]);
        expect(list[0]).toMatchObject({ status: 'delivered', error: null, status_at: '14:05', multi_fail: true });
        for (const gone of ['error_code', 'category', 'action', 'meta_code']) expect(list[0]).not.toHaveProperty(gone);

        applyUpdates(list, [{ id: 1, status: 'read', error: null, status_at: '14:06', multi_fail: true, attempts: 1, can_resend: false }]);
        expect(list[0]).toMatchObject({ status: 'read', status_at: '14:06', attempts: 1, can_resend: false });
    });

    it('reloads on a gap', () => {
        expect(needsReload({ gap: true })).toBe(true);
        expect(needsReload({ messages: [] })).toBe(false);
    });
});
