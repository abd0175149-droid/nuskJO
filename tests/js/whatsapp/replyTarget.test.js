import { describe, expect, it } from 'vitest';
import { createReplyTarget, snippetOf } from '@/composables/whatsapp/useReplyTarget.js';

// الرسالة التي يرد عليها الموظف (E33): بمعرّفنا لكل محادثة، والمقتطف 80 حرفاً للعرض وحده
describe('useReplyTarget', () => {
    it('keeps one target per conversation and sends our id only', () => {
        const t = createReplyTarget();
        expect(t.set(3, { id: 10, body: 'كم السعر؟', direction: 'in', can_quote: true, wamid: 'x' })).toBe(true);
        expect(t.get(3)).toEqual({ id: 10, snippet: 'كم السعر؟', direction: 'in' });
        expect(t.payload(3)).toEqual({ reply_to_id: 10 });
        expect(t.get(4)).toBeNull();
        expect(t.payload(4)).toEqual({});
        t.clear(3);
        expect(t.get(3)).toBeNull();
    });

    it('refuses a message that cannot be quoted', () => {
        const t = createReplyTarget();
        expect(t.set(3, { id: 11, body: '👍', can_quote: false })).toBe(false);
        expect(t.get(3)).toBeNull();
    });

    it('cuts the snippet by characters on one line', () => {
        const long = `${'عمرة '.repeat(30)}\nسطر ثانٍ`;
        const s = snippetOf({ body: long });
        expect([...s].length).toBe(81);
        expect(s.endsWith('…')).toBe(true);
        expect(s).not.toContain('\n');
        expect(snippetOf({ body: '👨‍👩‍👧 عائلة' })).toBe('👨‍👩‍👧 عائلة');
    });
});
