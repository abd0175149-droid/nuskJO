import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ChannelHealthCard from '@/Components/WhatsApp/ChannelHealthCard.vue';

const base = {
    enabled: true, available: true, state: 'AVAILABLE', label: 'تعمل', severity: 'ok', reason: null, source: 'poll', source_label: 'فحص دوري',
    until: null, checked_at: new Date(Date.now() - 5 * 60000).toISOString(), changed_at: null, unblock_suggested: false, blocks: false,
    block_on_token: false, breaker_lock: false, meta: {}, consecutive_failures: 0, last_error: null, foreign_today: 0,
    manual: { on: false, source: null, reason: null, at: null, by_name: null }, events: [],
};
const health = (over = {}) => ({ ...base, ...over });

describe('ChannelHealthCard', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows the state badge, source and the last check age', () => {
        const w = mount(ChannelHealthCard, { props: { health: health() } });
        expect(w.find('[data-test="health-badge"]').text()).toContain('تعمل');
        expect(w.text()).toContain('المصدر: فحص دوري');
        expect(w.text()).toContain('آخر فحص قبل 5 د');
        expect(w.find('[data-test="health-off"]').exists()).toBe(false);
    });

    it('keeps the manual suspension on its own line, apart from the health state', () => {
        const w = mount(ChannelHealthCard, { props: { health: health({ manual: { on: true, source: 'manual', reason: 'صيانة مجدولة الليلة', by_name: 'سارة' } }) } });
        expect(w.find('[data-test="manual-line"]').text()).toContain('موقوف يدوياً بواسطة سارة: صيانة مجدولة الليلة');
        expect(w.find('[data-test="health-badge"]').text()).toContain('تعمل');
    });

    it('warns that Meta will reject sends while the token is invalid and the block flag is off', () => {
        expect(mount(ChannelHealthCard, { props: { health: health({ state: 'TOKEN_INVALID' }) } }).find('[data-test="token-bar"]').exists()).toBe(true);
        expect(mount(ChannelHealthCard, { props: { health: health({ state: 'TOKEN_INVALID', block_on_token: true }) } }).find('[data-test="token-bar"]').exists()).toBe(false);
    });

    it('offers to lift or acknowledge when Meta suggests unblocking, and never lifts by itself', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { channelHealth: health({ state: 'BLOCKED', unblock_suggested: false }) } });
        const w = mount(ChannelHealthCard, { props: { health: health({ state: 'BLOCKED', unblock_suggested: true, reason: 'ban:DISABLE' }) } });

        expect(w.find('[data-test="suggestion"]').text()).toContain('ميتا تشير إلى رفع القيد');
        const ack = w.findAll('[data-test="suggestion"] button').find((b) => b.text() === 'تم الاطلاع');
        await ack.trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/channel-health/acknowledge', {}, expect.anything());
        expect(w.emitted('changed')[0][0].unblock_suggested).toBe(false);
    });

    it('lifts only with a reason and the typed word, and shows Meta’s refusal', async () => {
        const post = vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 409, data: { message: 'ميتا ما زالت تحجب الرقم — الرفع لن يفيد', possible_solution: 'Visit business settings.' } } });
        const w = mount(ChannelHealthCard, { props: { health: health({ state: 'BLOCKED' }) }, attachTo: document.body });

        await w.findAll('button').find((b) => b.text().startsWith('ارفع القفل')).trigger('click');
        await flushPromises();
        const submit = () => w.find('form button[type="submit"]');
        expect(submit().attributes('disabled')).toBeDefined();

        await w.find('#ch-reason').setValue('راجعنا البريد ورُفع القيد');
        await w.find('#ch-confirm').setValue('رفع');
        expect(submit().attributes('disabled')).toBeUndefined();
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/channel-health/unblock', { reason: 'راجعنا البريد ورُفع القيد', confirm_text: 'رفع' }, expect.anything());
        expect(w.text()).toContain('ميتا ما زالت تحجب الرقم — الرفع لن يفيد — Visit business settings.');
        expect(w.emitted('changed')).toBeUndefined();
        w.unmount();
    });

    it('shows quality, the business-initiated tier note, the failure counter and the off-flag notice', () => {
        const w = mount(ChannelHealthCard, { props: { health: health({ enabled: false, consecutive_failures: 4, meta: { quality_rating: 'RED', tier: 'TIER_1K' } }) } });
        expect(w.text()).toContain('الجودة: حمراء');
        expect(w.text()).toContain('يخص الرسائل التي تبدؤها الشركة فقط');
        expect(w.find('[data-test="consecutive"]').text()).toContain('4');
        expect(w.find('[data-test="health-off"]').exists()).toBe(true);
    });
});
