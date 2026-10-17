import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CallFromAgencyPhone from '@/Components/WhatsApp/CallFromAgencyPhone.vue';
import CallingCard from '@/Components/WhatsApp/CallingCard.vue';
import ChannelHealthCard from '@/Components/WhatsApp/ChannelHealthCard.vue';
import InboxStatusBar from '@/Components/WhatsApp/InboxStatusBar.vue';
import CoexistenceCard from '@/Components/WhatsApp/CoexistenceCard.vue';
import ConversationList from '@/Components/WhatsApp/ConversationList.vue';
import MessageBubble from '@/Components/WhatsApp/MessageBubble.vue';

// المكالمات والتعايش (E39): فقاعة المكالمة، ووسم هاتف الوكالة والتعديل والحذف، والفلتر، وزر المعاودة، وبطاقتا الإعدادات
const msg = (over = {}) => ({ id: 1, direction: 'in', source: 'customer', body: '', at: '10:00', status: null, ...over });

const calling = (over = {}) => ({
    enabled: true,
    followup_enabled: false,
    snapshot: { calling: { status: 'ENABLED', call_icon_visibility: 'DEFAULT', callback_permission_status: 'ENABLED', call_hours: { status: 'ENABLED', timezone_id: 'Asia/Amman' } }, source: 'read', at: '2026-10-16T08:00:00+00:00' },
    answer_mode: 'none',
    answer_modes: ['none', 'webrtc', 'sip'],
    auto_reject: false,
    warning: true,
    followup: { text: null, text_en: null, default: 'النص المقترح {company.phone}', effective: 'النص المقترح {company.phone}', variables: {}, hours: 6 },
    missed: { today: 2, week: 5 },
    rejected: { today: 0, week: 0 },
    ...over,
});

const coexistence = (over = {}) => ({
    flags: { guard: true, echoes: false, history_hold: false },
    holder_id: null,
    holder_valid: false,
    candidates: [{ id: 7, name: 'سامي' }],
    echo_pause_minutes: null,
    pause_minutes: 30,
    echoes_today: 3,
    history: { last: null, hold: { held: 0 }, hold_days: 30 },
    state_sync: null,
    link: null,
    ...over,
});

describe('MessageBubble calls and phone echoes', () => {
    it('shows a missed call as a light red centred strip and a completed call in grey', () => {
        const missed = mount(MessageBubble, { props: { m: msg({ is_system: true, type: 'call', body: '📞 مكالمة واردة فائتة 10:42', call: { missed: true } }) } });
        const pill = missed.find('[data-test="call-pill"]');
        expect(pill.text()).toContain('فائتة');
        expect(pill.classes()).toContain('bg-red-50');
        expect(missed.find('.rounded-2xl').exists()).toBe(false);

        const done = mount(MessageBubble, { props: { m: msg({ is_system: true, type: 'call', body: '📞 مكالمة واردة، مدتها 3:12', call: { missed: false } }) } });
        expect(done.find('[data-test="call-pill"]').classes()).toContain('bg-gray-200');
    });

    it('labels a phone echo with the holder and marks edits and deletions without hiding the text', () => {
        const w = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff_phone', sender: '📱 من هاتف الوكالة · سامي', body: 'الأصل', phone: { edited: true, revoked: true } }) } });
        expect(w.text()).toContain('📱 من هاتف الوكالة · سامي');
        expect(w.text()).not.toContain('👤 📱');
        expect(w.text()).toContain('الأصل');
        expect(w.find('[data-test="phone-edited"]').exists()).toBe(true);
        expect(w.find('[data-test="phone-revoked"]').text()).toContain('حُذفت من الهاتف');
        expect(w.html()).toContain('bg-gold-500');
    });

    it('introduces an edit row as the new text from the phone', () => {
        const w = mount(MessageBubble, { props: { m: msg({ direction: 'out', source: 'staff_phone', type: 'edit', body: 'النص الجديد', reply_to: 'الأصل' }) } });
        expect(w.find('[data-test="phone-edit"]').text()).toContain('عُدّلت من الهاتف');
        expect(w.text()).toContain('📱 هاتف الوكالة');
    });
});

describe('ConversationList missed calls filter', () => {
    it('offers the filter only with the calls flag', async () => {
        const off = mount(ConversationList, { props: { items: [] } });
        expect(off.text()).not.toContain('مكالمات فائتة');

        const on = mount(ConversationList, { props: { items: [], missedCalls: true } });
        const btn = on.findAll('button').find((b) => b.text().includes('📞 مكالمات فائتة'));
        await btn.trigger('click');
        expect(on.emitted('update:filter')[0]).toEqual(['missed_call']);
    });
});

describe('CallFromAgencyPhone', () => {
    afterEach(() => vi.restoreAllMocks());

    it('reveals the customer number for copying only', async () => {
        const write = vi.fn().mockResolvedValue();
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: write }, configurable: true });
        const w = mount(CallFromAgencyPhone, { props: { phone: '16505551234' } });
        expect(w.find('[data-test="call-number"]').exists()).toBe(false);

        await w.find('[data-test="call-from-agency"]').trigger('click');
        expect(w.find('[data-test="call-number"]').text()).toContain('+1 650 555 1234');
        expect(w.find('a[href^="tel:"]').exists()).toBe(false);

        await w.find('[data-test="copy-number"]').trigger('click');
        await flushPromises();
        expect(write).toHaveBeenCalledWith('+16505551234');
        expect(w.text()).toContain('✅ نُسخ');
    });
});

describe('CallingCard', () => {
    afterEach(() => vi.restoreAllMocks());

    it('warns in red when calling is on at Meta without an answer method, and has no apply button', () => {
        const w = mount(CallingCard, { props: { calling: calling() } });
        expect(w.find('[data-test="calling-warning"]').text()).toContain('كل مكالمة ستفوت');
        expect(w.find('[data-test="calling-counts"]').text()).toContain('2');
        expect(w.text()).toContain('whatsapp:calling-settings');
        expect(w.text()).toContain('العلم calls_followup مطفأ');
        expect(w.findAll('button').map((b) => b.text()).join(' ')).not.toMatch(/تفعيل الاتصال|تطبيق/);
    });

    it('reads from Meta and saves the answer method, auto reject and follow-up text', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { calling: calling({ warning: false, snapshot: { calling: { status: 'DISABLED' }, source: 'read', at: null } }) } });
        const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { calling: calling({ answer_mode: 'webrtc', warning: false }) } });
        const w = mount(CallingCard, { props: { calling: calling() } });

        await w.find('[data-test="calling-refresh"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/api/whatsapp/calling/refresh', {}, expect.anything());
        expect(w.find('[data-test="calling-warning"]').exists()).toBe(false);

        await w.find('#calls-answer-mode').setValue('webrtc');
        await w.find('[data-test="calls-auto-reject"]').setValue(true);
        await w.find('#calls-followup').setValue('وصلتنا مكالمتك {company.phone}');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(put).toHaveBeenCalledWith('/api/whatsapp/calling/settings',
            { answer_mode: 'webrtc', auto_reject: true, followup_text: 'وصلتنا مكالمتك {company.phone}', followup_text_en: '' }, expect.anything());
        expect(w.emitted('changed')).toHaveLength(1);
    });

    it('shows the rule guard refusal under the text', async () => {
        vi.spyOn(axios, 'put').mockRejectedValue({ response: { status: 422, data: { errors: { followup_text: ['لا أسعار في الرد الثابت'] } } } });
        const w = mount(CallingCard, { props: { calling: calling() } });
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(w.text()).toContain('لا أسعار في الرد الثابت');
    });
});

describe('CoexistenceCard', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows the flags and counts, and saves the holder', async () => {
        const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { coexistence: coexistence({ holder_id: 7, holder_valid: true }) } });
        const w = mount(CoexistenceCard, { props: { coexistence: coexistence() } });
        expect(w.find('[data-test="coexistence-flags"]').text()).toContain('✅ الحارس المبكر');
        expect(w.text()).toContain('لا يُحفظ نصها');
        expect(w.find('[data-test="coexistence-counts"]').text()).toContain('3');

        await w.find('#coex-holder').setValue(7);
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(put).toHaveBeenCalledWith('/api/whatsapp/coexistence/settings', { holder_user_id: 7, echo_pause_minutes: null }, expect.anything());
        expect(w.emitted('changed')).toHaveLength(1);
    });

    it('warns about a broken link and held history awaiting a decision', () => {
        const w = mount(CoexistenceCard, { props: { coexistence: coexistence({ link: { state: 'removed' }, history: { last: { threads: 2, messages: 3, at: null }, hold: { held: 1, messages: 3, purge_soonest: '2026-11-15 00:00:00' } } }) } });
        expect(w.find('[data-test="coexistence-link-removed"]').text()).toContain('أعد الربط');
        expect(w.find('[data-test="history-held"]').text()).toContain('whatsapp:coexistence-history');
    });
});

describe('channel health and inbox badges', () => {
    it('badges a broken coexistence link in the channel health card without any lock wording', () => {
        const health = {
            enabled: true, available: true, state: 'AVAILABLE', label: 'تعمل', severity: 'ok', reason: null, source: 'poll', source_label: null,
            until: null, checked_at: null, changed_at: null, unblock_suggested: false, blocks: false, block_on_token: false, breaker_lock: false,
            meta: { coexistence: { state: 'removed', reason: 'PRIMARY_INACTIVITY', at: '2026-10-16T08:00:00+00:00' } },
            consecutive_failures: 0, last_error: null, foreign_today: 0, manual: { on: false }, events: [],
        };
        const w = mount(ChannelHealthCard, { props: { health } });
        const badge = w.find('[data-test="coexistence-removed"]');
        expect(badge.text()).toContain('خمول الهاتف الأساسي نحو 14 يوماً');
        expect(badge.text()).toContain('لم يُقفل');

        health.meta = { coexistence: { state: 'linked' } };
        expect(mount(ChannelHealthCard, { props: { health } }).find('[data-test="coexistence-removed"]').exists()).toBe(false);
    });

    it('shows the coexistence badge in the inbox bar with the flag only', () => {
        const base = { llm: { state: 'closed' }, staleSince: '', notice: '' };
        expect(mount(InboxStatusBar, { props: { ...base, status: { channel_ready: true } } }).find('[data-test="coexistence-badge"]').exists()).toBe(false);
        expect(mount(InboxStatusBar, { props: { ...base, status: { channel_ready: true, coexistence: true } } }).find('[data-test="coexistence-badge"]').text()).toContain('📱 التعايش مفعّل');
    });
});
