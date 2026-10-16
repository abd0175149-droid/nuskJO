import { describe, expect, it } from 'vitest';
import { groupReplies, hasUnfilled, insertReply, unfilledVariables } from '@/composables/whatsapp/useQuickReplyRender.js';

describe('quick reply rendering', () => {
    it('finds {{ name }} with spaces and Arabic names, but not {name}', () => {
        expect(unfilledVariables('أهلاً {{ name }} و{{name}} و{{الاسم}}')).toEqual(['name', 'الاسم']);
        expect(hasUnfilled('أهلاً {name}')).toBe(false);
        expect(hasUnfilled('')).toBe(false);
    });

    it('replaces an empty or slash-only draft and appends otherwise', () => {
        expect(insertReply('', 'نص')).toBe('نص');
        expect(insertReply('/', 'نص')).toBe('نص');
        expect(insertReply('مرحبا  ', 'نص')).toBe('مرحبا\nنص');
    });

    it('groups by category order and filters by title or body', () => {
        const replies = [
            { id: 1, title: 'ساعات الدوام', body: 'من 9 إلى 5', category: 'hours' },
            { id: 2, title: 'ترحيب', body: 'أهلاً {{name}}', category: 'general' },
            { id: 3, title: 'تأشيرة', body: 'المدة أسبوع', category: 'visa' },
        ];
        const cats = { general: 'عام', visa: 'التأشيرات', hours: 'الدوام' };
        expect(groupReplies(replies, cats).map((g) => g.key)).toEqual(['general', 'visa', 'hours']);
        expect(groupReplies(replies, cats, 'أسبوع').map((g) => g.items[0].id)).toEqual([3]);
    });
});
