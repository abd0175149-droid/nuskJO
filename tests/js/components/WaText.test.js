import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import WaText from '@/Components/WhatsApp/WaText.vue';

describe('WaText', () => {
    it('renders formatting as elements and links with a safe rel', () => {
        const w = mount(WaText, { props: { text: '*عريض* راجع https://nusk.example/a' } });
        expect(w.find('strong').text()).toBe('عريض');
        const a = w.find('a');
        expect(a.attributes('href')).toBe('https://nusk.example/a');
        expect(a.attributes('target')).toBe('_blank');
        expect(a.attributes('rel')).toBe('noopener noreferrer nofollow');
        expect(w.find('p').attributes('dir')).toBe('auto');
    });

    it('never turns customer text into markup', () => {
        const w = mount(WaText, { props: { text: '<img src=x onerror=alert(1)> javascript:alert(1)' } });
        expect(w.find('img').exists()).toBe(false);
        expect(w.find('a').exists()).toBe(false);
        expect(w.text()).toContain('<img src=x onerror=alert(1)>');
    });
});
