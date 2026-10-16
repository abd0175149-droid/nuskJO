import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ChoiceChips from '@/Components/WhatsApp/ChoiceChips.vue';
import QuoteFlowField from '@/Components/WhatsApp/QuoteFlowField.vue';

const msg = (over = {}) => ({ id: 7, direction: 'out', source: 'bot', type: 'interactive', body: 'اختر', at: '10:00', ...over });

describe('ChoiceChips (E36)', () => {
    it('shows what was offered as non-clickable pills with an expired badge', () => {
        const w = mount(ChoiceChips, { props: { m: msg({ choices: { type: 'list', items: [{ title: 'عمرة الربيع', description: 'يبدأ من 220 د.أ للفرد' }, { title: '<b>فندق</b>' }], expired: true } }) } });
        const items = w.findAll('[data-test="choice-item"]');
        expect(items.map((i) => i.text())).toEqual(['عمرة الربيع · يبدأ من 220 د.أ للفرد', '<b>فندق</b>']);
        expect(w.find('b').exists()).toBe(false);
        expect(w.find('button').exists()).toBe(false);
        expect(w.find('a').exists()).toBe(false);
        expect(items[0].attributes('dir')).toBe('auto');
        expect(w.find('[data-test="choices-expired"]').text()).toBe('منتهية');
        expect(w.text()).toContain('قائمة أُرسلت للعميل');
    });

    it('marks a valid choice green and a stale one amber with its reason', () => {
        const ok = mount(ChoiceChips, { props: { m: msg({ direction: 'in', chosen: { label: 'عمرة الربيع', valid: true, reason_label: null } }) } });
        expect(ok.find('[data-test="choice-chosen"]').text()).toBe('🔘 اختيار: عمرة الربيع');
        expect(ok.find('[data-test="choice-chosen"]').classes()).toContain('bg-emerald-100');
        expect(ok.find('[data-test="choice-reason"]').exists()).toBe(false);

        const stale = mount(ChoiceChips, { props: { m: msg({ direction: 'in', chosen: { label: 'عمرة قديمة', valid: false, reason_label: 'قائمة قديمة' } }) } });
        expect(stale.find('[data-test="choice-chosen"]').classes()).toContain('bg-amber-100');
        expect(stale.find('[data-test="choice-reason"]').text()).toBe('— قائمة قديمة');
    });

    it('links a quote form reply to its request only when the server sends the link', () => {
        const linked = mount(ChoiceChips, { props: { m: msg({ direction: 'in', quote_form: { valid: true, duplicate: false, request_number: 'Q-0042', url: '/quote-requests?status=all&search=Q-0042' } }) } });
        expect(linked.find('[data-test="quote-form-link"]').attributes('href')).toBe('/quote-requests?status=all&search=Q-0042');
        expect(linked.text()).toContain('📝 نموذج طلب تسعير');

        const plain = mount(ChoiceChips, { props: { m: msg({ direction: 'in', quote_form: { valid: true, request_number: 'Q-0042' } }) } });
        expect(plain.find('a').exists()).toBe(false);
        expect(plain.text()).toContain('الطلب Q-0042');

        const invalid = mount(ChoiceChips, { props: { m: msg({ direction: 'in', quote_form: { valid: false } }) } });
        expect(invalid.find('[data-test="quote-form-invalid"]').exists()).toBe(true);
    });

    it('renders nothing for an ordinary message', () => {
        expect(mount(ChoiceChips, { props: { m: msg({ type: 'text' }) } }).find('[data-test="choice-chips"]').exists()).toBe(false);
    });
});

describe('QuoteFlowField (E36)', () => {
    it('keeps the flow id to latin digits and emits null when empty', async () => {
        const w = mount(QuoteFlowField, { props: { flowId: null, mode: 'published', flagOn: true } });
        const input = w.find('#wa-quote-flow-id');
        await input.setValue('١٢٣abc456');
        expect(w.emitted('update:flowId').at(-1)).toEqual(['123456']);
        expect(input.element.value).toBe('123456');
        await input.setValue('');
        expect(w.emitted('update:flowId').at(-1)).toEqual([null]);
    });

    it('switches between draft and published and says when the flag is off', async () => {
        const w = mount(QuoteFlowField, { props: { flowId: '123', mode: 'published', flagOn: false } });
        expect(w.find('[data-test="flag-off"]').exists()).toBe(true);
        await w.find('#wa-quote-flow-mode').setValue('draft');
        expect(w.emitted('update:mode')[0]).toEqual(['draft']);
        expect(w.find('[data-test="meta-steps"]').attributes('rel')).toBe('noopener noreferrer nofollow');
        expect(w.find('[role="note"]').text()).toContain('لا تضف إلى النموذج أي حقل جواز أو هوية');
    });

    it('shows the server errors and the dirty ring', () => {
        const w = mount(QuoteFlowField, { props: { dirty: true, errors: { wa_quote_flow_id: 'أرقام فقط' } } });
        expect(w.text()).toContain('أرقام فقط');
        expect(w.find('[data-test="quote-flow-field"]').classes()).toContain('ring-1');
    });
});
