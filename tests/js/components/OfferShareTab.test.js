import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import OfferShareTab from '@/Components/Offers/OfferShareTab.vue';

// تبويب المشاركة (E38): بطاقة الحالة 9:16 وحدها بعد إزالة رموز ميتا الرسمية
const share = (over = {}) => ({
    for_bot: true, blockers: [], story: { url: null, is_stale: true, generated_at: null }, can: { update: true }, ...over,
});

afterEach(() => vi.restoreAllMocks());

describe('OfferShareTab', () => {
    it('loads the story card and regenerates it', async () => {
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: share() });
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true } });
        const w = mount(OfferShareTab, { props: { offerId: 45 } });
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/api/offers/45/share', expect.anything());
        expect(w.find('[data-test="story-download"]').attributes('href')).toBe('/offers/45/story/download');
        expect(w.text()).not.toContain('QR');

        await w.find('[data-test="story-generate"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/offers/45/story', {}, expect.anything());
        expect(get).toHaveBeenCalledTimes(2);
    });

    it('disables the story for an offer the bot cannot see', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: share({ for_bot: false, blockers: ['ظاهر للبوت'] }) });
        const w = mount(OfferShareTab, { props: { offerId: 45 } });
        await flushPromises();

        expect(w.find('[data-test="not-for-bot"]').text()).toContain('ظاهر للبوت');
        expect(w.find('[data-test="story-generate"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="story-download"]').exists()).toBe(false);
    });
});
