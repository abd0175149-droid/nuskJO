import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AutoRuleEditor from '@/Components/WhatsApp/AutoRuleEditor.vue';

const props = (over = {}) => ({
    open: true,
    topics: { visa: 'تأشيرات', umrah: 'عمرة' },
    variables: { 'company.address': 'عنوان المكتب', 'company.hours': 'مواعيد الدوام' },
    msgTypes: { text: 'نص', interactive: 'اختيار من قائمة', button: 'زر', audio: 'صوت مفرّغ' },
    limits: { patterns: 3, pattern_len: 60, reply: 1024 },
    ...over,
});
const mountEditor = (over = {}) => mount(AutoRuleEditor, { props: props(over), attachTo: document.body });

describe('AutoRuleEditor (E37)', () => {
    it('adds patterns as chips on Enter, up to the limit and without duplicates', async () => {
        const w = mountEditor();
        const input = w.find('#ar-pattern');
        for (const v of ['الموقع', 'الموقع', 'العنوان', 'وين', 'زيادة']) {
            await input.setValue(v);
            await input.trigger('keydown', { key: 'Enter' });
        }
        expect(w.find('[data-test="patterns"]').text()).toContain('الموقع');
        expect(w.findAll('[data-test="patterns"] > span')).toHaveLength(3);
        expect(input.attributes('disabled')).toBeDefined();
        await w.findAll('[data-test="patterns"] button')[0].trigger('click');
        expect(w.findAll('[data-test="patterns"] > span')).toHaveLength(2);
        w.unmount();
    });

    it('submits only the conditions that differ from the defaults', async () => {
        const w = mountEditor();
        await w.find('#ar-name').setValue('الموقع');
        await w.find('#ar-pattern').setValue('موقعكم');
        await w.find('#ar-text-ar, #ar-reply_text_ar').setValue('مكتبنا في {company.address}');
        await w.find('form').trigger('submit');
        let payload = w.emitted('submit')[0][0];
        expect(payload.patterns).toEqual(['موقعكم']);
        expect(payload.conditions).toBeNull();
        expect(payload.reply_text_en).toBeNull();
        expect(payload.match_type).toBe('any_of');

        await w.find('#ar-linked').setValue('yes');
        await w.findAll('[role="group"][aria-label="المواضيع"] button')[0].trigger('click');
        await w.find('#ar-referral').setValue('yes');
        await w.find('form').trigger('submit');
        payload = w.emitted('submit')[1][0];
        expect(payload.conditions).toEqual({ linked: 'yes', topics_any: ['visa'], has_referral: true });
        w.unmount();
    });

    it('inserts a company variable at the cursor', async () => {
        const w = mountEditor();
        const ta = w.find('#ar-reply_text_ar');
        await ta.setValue('مكتبنا في ');
        ta.element.setSelectionRange(10, 10);
        await w.findAll('[aria-label="إدراج متغير في نص الرد بالعربية *"] button')[0].trigger('click');
        expect(ta.element.value).toBe('مكتبنا في {company.address}');
        w.unmount();
    });

    it('fills the draft from an existing rule and warns that editing stops an active one', () => {
        const w = mountEditor({ rule: { id: 4, name: 'الدوام', match_type: 'exact', patterns: ['الدوام'], is_active: true,
            conditions: { msg_types: ['text'], exclude_staff: false }, max_words: 4, cooldown_minutes: 60, reply_text_ar: 'الدوام {company.hours}' } });
        expect(w.find('#ar-name').element.value).toBe('الدوام');
        expect(w.find('#ar-type').element.value).toBe('exact');
        expect(w.text()).toContain('يوقف القاعدة حتى تُجرَّب من جديد');
        expect(w.findAll('input[type="checkbox"]').filter((c) => c.element.checked).map((c) => c.element.value)).toEqual(['text']);
        w.unmount();
    });

    it('shows the server guard errors under each field', () => {
        const w = mountEditor({ errors: { reply_text_ar: 'لا أسعار ولا خصومات بأرقام في الرد الثابت', 'patterns.0': 'القيمة فارغة بعد حذف الترقيم' } });
        expect(w.find('[data-test="error-reply_text_ar"]').text()).toContain('لا أسعار');
        expect(w.text()).toContain('القيمة فارغة بعد حذف الترقيم');
        w.unmount();
    });
});
