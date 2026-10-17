import { describe, expect, it } from 'vitest';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const fail = (status, data) => ({ response: { status, data } });

describe('errorText', () => {
    it('appends the suggested action after Meta’s reason (E18)', () => {
        expect(errorText(fail(422, { error: 'تعذّر التسليم', action: 'اطلب من العميل تحديث التطبيق' }), 'x'))
            .toBe('تعذّر التسليم — اطلب من العميل تحديث التطبيق');
    });

    it('keeps the reason alone when there is no action, and the fallback when there is nothing', () => {
        expect(errorText(fail(422, { error: 'الإرسال موقوف' }), 'x')).toBe('الإرسال موقوف');
        expect(errorText(fail(500, {}), 'تعذّر الإرسال')).toBe('تعذّر الإرسال');
    });

    it('never shows Laravel’s English 403', () => {
        expect(errorText(fail(403, { message: 'This action is unauthorized.' }), 'x')).toBe('ليست لديك صلاحية لهذا الإجراء');
    });
});
