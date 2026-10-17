import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BusinessProfileCard from '@/Components/WhatsApp/BusinessProfileCard.vue';

// ملف واتساب للأعمال (E31): المقارنة بما عند ميتا، واختيار المختلف وحده، ومراجعة قبل/بعد ثم المزامنة، واسم العرض للقراءة فقط
const overview = (over = {}) => ({
    ok: true,
    current: { about: 'مكتب سفر وعمرة', description: null, address: 'عمّان، الأردن', email: 'info@example.com', websites: ['https://example.com'], vertical: 'OTHER', profile_picture_url: null },
    desired: {},
    diff: {
        about: { current: 'مكتب سفر وعمرة', desired: 'وكالة عمرة وسفر في عمّان', status: 'different' },
        description: { current: null, desired: null, status: 'missing_local' },
        address: { current: 'عمّان، الأردن', desired: 'عمّان، الأردن', status: 'same' },
        email: { current: 'info@example.com', desired: 'info@example.com', status: 'same' },
        websites: { current: ['https://example.com'], desired: ['https://a.example.com', 'https://b.example.com', 'https://facebook.com/x'], status: 'too_long' },
        vertical: { current: 'OTHER', desired: 'TRAVEL', status: 'different' },
    },
    identity: { verified_name: 'NUSUK Gold Travel', name_status: 'APPROVED', display_phone_number: '15550783881' },
    can: { sync: true, photo: false, app_id: true },
    ...over,
});

describe('BusinessProfileCard', () => {
    afterEach(() => vi.restoreAllMocks());

    it('reads the comparison and shows the display name read-only with the WhatsApp Manager link', async () => {
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: overview() });
        const w = mount(BusinessProfileCard);
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/api/whatsapp/business-profile', expect.objectContaining({ params: {} }));
        const name = w.find('[data-test="display-name"]');
        expect(name.text()).toContain('NUSUK Gold Travel');
        expect(name.text()).toContain('معتمد');
        expect(name.text()).toContain('يُغيَّر من WhatsApp Manager فقط');
        expect(name.find('a').attributes('href')).toBe('https://business.facebook.com/wa/manage/phone-numbers/');
        expect(w.text()).toContain('الروابط في النبذة (about) لا تكون قابلة للنقر');

        expect(w.find('[data-test="profile-row-address"]').text()).toContain('✔ مطابق');
        expect(w.find('[data-test="profile-row-websites"]').text()).toContain('⚠ طويل');
        // مربع الاختيار للمختلف وحده
        expect(w.findAll('input[type="checkbox"]').map((c) => c.attributes('value'))).toEqual(['about', 'vertical']);
    });

    it('reviews before and after, then syncs only the picked fields', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: overview() });
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true, verified: true, diff: overview().diff } });
        const w = mount(BusinessProfileCard, { attachTo: document.body });
        await flushPromises();

        expect(w.find('[data-test="profile-sync"]').attributes('disabled')).toBeDefined();
        await w.find('input[value="vertical"]').setValue(true);
        await w.find('[data-test="profile-sync"]').trigger('click');
        const review = w.find('[data-test="profile-review"]');
        expect(review.text()).toContain('قبل: OTHER');
        expect(review.text()).toContain('بعد: TRAVEL');

        await w.find('[data-test="profile-confirm"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/api/whatsapp/business-profile/sync', { fields: ['vertical'] }, expect.anything());
        expect(w.find('[data-test="profile-notice"]').text()).toContain('تحققنا منه');
        w.unmount();
    });

    it('stays read-only when sync is off, and explains a failed read', async () => {
        vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: overview({ can: { sync: false, photo: false, app_id: false } }) });
        const w = mount(BusinessProfileCard);
        await flushPromises();
        expect(w.find('[data-test="profile-sync"]').exists()).toBe(false);
        expect(w.findAll('input[type="checkbox"]')).toHaveLength(0);
        expect(w.text()).toContain('المزامنة مطفأة حالياً');

        vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: { ok: false, code: 'NOT_CONFIGURED', error: 'قناة الواتساب غير مهيّأة.', can: { sync: false } } });
        const broken = mount(BusinessProfileCard);
        await flushPromises();
        expect(broken.find('[data-test="profile-error"]').text()).toContain('غير مهيّأة');
    });
});
