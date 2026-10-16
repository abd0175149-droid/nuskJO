import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HighlightedSnippet from '@/Components/WhatsApp/HighlightedSnippet.vue';

// المقتطف (E30.7): أجزاء نصية، والمطابق داخل <mark>، ولا عنصر HTML من نص العميل أبداً
describe('HighlightedSnippet', () => {
    it('marks the highlighted parts only', () => {
        const w = mount(HighlightedSnippet, { props: { segments: [{ t: 'نريد ', hl: false }, { t: 'العُمرة', hl: true }, { t: ' في رمضان', hl: false }] } });
        expect(w.findAll('mark').map((m) => m.text())).toEqual(['العُمرة']);
        expect(w.text()).toBe('نريد العُمرة في رمضان');
        expect(w.find('span[dir="auto"]').exists()).toBe(true);
    });

    it('renders customer markup as text, never as elements', () => {
        const w = mount(HighlightedSnippet, { props: { segments: [{ t: '<img src=x onerror=alert(1)>', hl: false }, { t: '<script>alert(2)</script>', hl: true }] } });
        expect(w.find('img').exists()).toBe(false);
        expect(w.find('script').exists()).toBe(false);
        expect(w.text()).toContain('<img src=x onerror=alert(1)>');
        expect(w.find('mark').text()).toBe('<script>alert(2)</script>');
    });
});
