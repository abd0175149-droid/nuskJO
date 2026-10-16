import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import EntrySourcesPanel from '@/Components/WhatsApp/EntrySourcesPanel.vue';
import OfferShareTab from '@/Components/Offers/OfferShareTab.vue';
import QrLinkCard from '@/Components/WhatsApp/QrLinkCard.vue';
import ThreadHeader from '@/Components/WhatsApp/ThreadHeader.vue';

// رموز QR الرسمية ومصدر المحادثة (E38): البطاقة وشارة المرجع المحذوف، وتبويب المشاركة، والمصادر، وشارة الخيط
const link = (over = {}) => ({
    id: 7, channel: 'print', channel_label: 'طباعة', label: 'ملصق المعرض', status: 'active',
    deep_link_url: 'https://wa.me/message/ABC', prefilled_message: 'مرحباً، أريد تفاصيل عرض «عمرة رجب» [N-45/Q7]', prefilled_len: 44,
    token_ok: true, last_error: null, png_url: '/offers/45/qr/7/png', svg_url: '/offers/45/qr/7/svg', has_png: true, on_card: false,
    conversations: 3, quotes: 1, ...over,
});
const share = (over = {}) => ({
    flags: { qr: true, story: true }, for_bot: true, blockers: [], links: [link()], limit: { count: 12, max: 2000, warn: 1800 },
    channels: { print: 'طباعة', status: 'حالة واتساب' }, story: { url: null, is_stale: true, generated_at: null }, can: { update: true }, ...over,
});

afterEach(() => vi.restoreAllMocks());

describe('QrLinkCard', () => {
    it('shows the text with its counter, the link, downloads and the usage', () => {
        const w = mount(QrLinkCard, { props: { link: link(), canManage: true, cardToggle: true } });
        expect(w.text()).toContain('44/140');
        expect(w.text()).toContain('https://wa.me/message/ABC');
        expect(w.find('[data-test="qr-usage"]').text()).toContain('بدأت منه 3 محادثة، و1 طلب تسعير (30 يوماً)');
        expect(w.findAll('a[download]').map((a) => a.attributes('href'))).toEqual(['/offers/45/qr/7/png', '/offers/45/qr/7/svg']);
        expect(w.text()).toContain('اطبعه على البطاقة');
        expect(w.find('[data-test="token-missing"]').exists()).toBe(false);
    });

    it('flags a reference Meta dropped, and hides management from viewers', async () => {
        const w = mount(QrLinkCard, { props: { link: link({ token_ok: false }), canManage: false, cardToggle: true } });
        expect(w.find('[data-test="token-missing"]').text()).toBe('المرجع حُذف من الرسالة');
        expect(w.text()).not.toContain('حذف');
        expect(w.text()).not.toContain('اطبعه على البطاقة');
    });
});

describe('OfferShareTab', () => {
    it('loads the share data and creates a code through the dialog', async () => {
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: share() });
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true, warning: null, link: link({ id: 8 }) } });
        const w = mount(OfferShareTab, { props: { offerId: 45 }, attachTo: document.body });
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/api/offers/45/share', expect.anything());
        expect(w.find('[data-test="qr-7"]').exists()).toBe(true);
        expect(w.text()).toContain('ميتا لا توفر إحصاءات للرموز');
        expect(w.find('[data-test="story-download"]').attributes('href')).toBe('/offers/45/story/download');

        await w.find('[data-test="qr-new"]').trigger('click');
        await flushPromises();
        await w.find('dialog form').trigger('submit');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/offers/45/qr', { channel: 'print', label: null }, expect.anything());
        expect(get).toHaveBeenCalledTimes(2);
        w.unmount();
    });

    it('disables new codes and the story for an offer the bot cannot see', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: share({ for_bot: false, blockers: ['ظاهر للبوت'] }) });
        const w = mount(OfferShareTab, { props: { offerId: 45 } });
        await flushPromises();

        expect(w.find('[data-test="not-for-bot"]').text()).toContain('ظاهر للبوت');
        expect(w.find('[data-test="qr-new"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="story-generate"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="story-download"]').exists()).toBe(false);
    });
});

describe('EntrySourcesPanel', () => {
    it('shows counts only and reloads the chosen period', async () => {
        const initial = { days: 30, note: 'قياس داخلي تقريبي', channels: { qr: 3, ad: 1 },
            rows: [{ channel: 'qr', offer_title: 'عمرة رجب', qr_label: 'ملصق', headline: null, conversations: 3, quotes: 1, won: 1 }] };
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { entries: { ...initial, days: 7, rows: [] } } });
        const w = mount(EntrySourcesPanel, { props: { initial } });

        expect(w.text()).toContain('📷 رمز QR: 3 محادثة');
        expect(w.text()).toContain('محسومة 1');
        expect(w.text()).toContain('قياس داخلي تقريبي');

        await w.findAll('button').find((b) => b.text().includes('7 يوماً')).trigger('click');
        await flushPromises();
        expect(get).toHaveBeenCalledWith('/api/whatsapp/analytics', expect.objectContaining({ params: { only: 'entries', entries_days: 7 } }));
        expect(w.text()).toContain('لا محادثات من رموز');
    });
});

describe('ThreadHeader entry badge', () => {
    it('says where the conversation came from', () => {
        const base = { id: 5, name: 'عميل', phone: '16505551234', topics: [] };
        const qr = mount(ThreadHeader, { props: { thread: { ...base, entry: { channel: 'qr', title: 'عمرة رجب' } } } });
        expect(qr.find('[data-test="entry-badge"]').text()).toBe('📷 من رمز: عمرة رجب');
        const ad = mount(ThreadHeader, { props: { thread: { ...base, entry: { channel: 'ad', headline: 'عروض رجب' } } } });
        expect(ad.find('[data-test="entry-badge"]').text()).toBe('📣 من إعلان: عروض رجب');
        expect(mount(ThreadHeader, { props: { thread: base } }).find('[data-test="entry-badge"]').exists()).toBe(false);
    });
});
