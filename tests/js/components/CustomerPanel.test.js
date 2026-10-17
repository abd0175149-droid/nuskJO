import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CustomerPanel from '@/Components/WhatsApp/Panel/CustomerPanel.vue';
import ThreadHeader from '@/Components/WhatsApp/ThreadHeader.vue';
import ThreadView from '@/Components/WhatsApp/ThreadView.vue';

// اللوحة الجانبية للمحادثة (C128): الأقسام وطيّها، والرحلة بفنادقها، والفواتير بصلاحيتها، والإجراءات، والرأس المختصر
const thread = (over = {}) => ({ id: 7, name: 'سامي', phone: '962791234567', window_open: true, window_left: 600, bot_enabled: true, topics: [], labels: [], ...over });

const trip = (over = {}) => ({
    key: 'inv-1', trip_date: '2026-11-16', return_date: '2026-11-23', nights: 7, days_until: 3, phase: 'upcoming', status: 'approved', status_label: 'معتمدة',
    kind: 'offer', offer: { title: 'عمرة الربيع', destination: 'مكة المكرمة', travel_mode: 'برّي' }, pax: 3,
    travellers: [{ pax: 2, room: 'ثنائية', choice: 'الصفوة' }, { pax: 1, room: 'ثلاثية', choice: '' }],
    hotels: [{ city: 'مكة', name: 'فندق الصفوة', rating: '5+', meals: 'إفطار', distance: '300 م', location: null, nights: 7 }], services: [], ...over,
});

const body = (over = {}) => ({
    identity: { trusted: true, linked: true, hint: null },
    clients: [{ name: 'سامي', code: 'CLT-042', source: 'linked' }],
    trips: [trip()], past_trips: [], invoices: null, summary: null, balance: null, quotes: [],
    notes: [{ text: 'يفضّل الطابق الأرضي', source: 'bot', at: '2026-10-17 10:00' }],
    conversation: { started_at: '2026-09-01', last_inbound_at: '2026-10-17 09:00', messages_in: 12, messages_out: 9, profile_name: null, username: null },
    ...over,
});

const mountPanel = (props = {}) => mount(CustomerPanel, { props: { thread: thread(), canReply: true, followup: true, ...props } });

describe('CustomerPanel', () => {
    beforeEach(() => localStorage.clear());
    afterEach(() => vi.restoreAllMocks());

    it('loads the panel for the conversation and shows the next trip with hotels and travellers', async () => {
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: body() });
        const w = mountPanel();
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/api/whatsapp/conversations/7/panel', expect.anything());
        expect(w.find('[data-test="next-trip-strip"]').text()).toContain('يسافر بعد 3 أيام');
        const card = w.find('[data-test="trip-card"]');
        expect(card.text()).toContain('عمرة الربيع');
        expect(card.text()).toContain('فندق الصفوة');
        expect(card.text()).toContain('★5+');
        expect(card.text()).toContain('300 م عن الحرم');
        expect(card.text()).toContain('3 مسافرين');
        expect(card.text()).toContain('7 ليالٍ');
        expect(w.emitted('badge').at(-1)).toEqual(['✈️ يسافر بعد 3 أيام']);
        expect(w.find('[data-test="panel-notes"]').text()).toContain('يفضّل الطابق الأرضي');
    });

    it('hides the invoices section without the permission and lists them with it', async () => {
        vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: body() });
        const plain = mountPanel();
        await flushPromises();
        expect(plain.find('[data-test="panel-section-invoices"]').exists()).toBe(false);
        expect(plain.find('[data-test="panel-balance"]').exists()).toBe(false);

        const invoices = [
            { id: 1, number: 'INV-1', date: '2026-10-01', status: 'approved', status_label: 'معتمدة', trip_date: '2026-11-16', total: '1250.500', offer: 'عمرة الربيع', seats: 3, url: '/invoices?highlight=1' },
            { id: 2, number: 'INV-2', date: '2026-10-10', status: 'pending', status_label: 'بانتظار الاعتماد', trip_date: null, total: '90.000', offer: null, seats: null, url: '/invoices?highlight=2' },
        ];
        vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: body({ invoices, summary: { count: 2, approved_count: 1, approved_total: '1250.500', pending_count: 1 },
            balance: { amount: '40.000', currency: 'JOD', direction: 'عليه', client: 'سامي' } }) });
        const boss = mountPanel();
        await flushPromises();
        const section = boss.find('[data-test="panel-section-invoices"]');
        expect(section.exists()).toBe(true);
        // مطويّ افتراضياً بملخص يكفي النظرة الأولى
        expect(section.text()).toContain('1 معتمدة بـ 1,250.500 د.أ · 1 بانتظار');
        await section.find('button').trigger('click');
        expect(boss.find('[data-test="panel-invoices"]').text()).toContain('INV-1');
        expect(boss.find('a[href="/invoices?highlight=2"]').exists()).toBe(true);
        expect(boss.find('[data-test="panel-balance"]').text()).toContain('عليه');
    });

    it('remembers a collapsed section for the next conversation', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: body() });
        const w = mountPanel();
        await flushPromises();
        const trips = w.find('[data-test="panel-section-trips"]');
        expect(trips.find('[data-test="trip-card"]').isVisible()).toBe(true);
        await trips.find('button').trigger('click');
        expect(localStorage.getItem('wa:panel:section:trips')).toBe('0');

        const again = mountPanel();
        await flushPromises();
        expect(again.find('[data-test="panel-section-trips"] [data-test="trip-card"]').isVisible()).toBe(false);
        expect(again.find('[data-test="panel-section-trips"] button').text()).toContain('عمرة الربيع');
    });

    it('shows the hint and no client data for a conversation without a visible number', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: body({ identity: { trusted: false, linked: false, hint: 'رقم العميل غير ظاهر، فلا تُعرض بياناته من النظام.' }, clients: [], trips: [] }) });
        const w = mountPanel({ thread: thread({ phone: null, username: 'sami' }) });
        await flushPromises();
        expect(w.find('[data-test="panel-hint"]').text()).toContain('غير ظاهر');
        expect(w.find('[data-test="next-trip-strip"]').exists()).toBe(false);
        expect(w.text()).toContain('@sami');
    });

    it('emits the conversation actions it shows', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: body() });
        const w = mountPanel({ canToggleBot: true, canLinkClient: true, canExport: true, canBlock: true });
        await flushPromises();
        const click = async (text) => {
            const btn = w.findAll('button').find((b) => b.text().includes(text));
            expect(btn, text).toBeTruthy();
            await btn.trigger('click');
        };
        await click('إيقاف البوت');
        await click('إغلاق المحادثة');
        await click('غير مقروءة');
        await click('ربط بعميل');
        await click('تصدير المحادثة');
        await click('حظر الرقم');
        await click('ساعة');
        expect(w.emitted('toggleBot')).toHaveLength(1);
        expect(w.emitted('action').map((a) => a[0])).toEqual(['close', 'markUnread', 'snooze']);
        expect(w.emitted('action')[2][1]).toEqual({ minutes: 60 });
        expect(w.emitted('link')).toHaveLength(1);
        expect(w.emitted('export')).toHaveLength(1);
        expect(w.emitted('block')).toHaveLength(1);
    });

    it('reloads when the conversation is linked to a client', async () => {
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: body() });
        const w = mountPanel();
        await flushPromises();
        await w.setProps({ thread: thread({ client_id: 42 }) });
        await flushPromises();
        expect(get).toHaveBeenCalledTimes(2);
    });
});

describe('ThreadHeader with the panel', () => {
    it('moves organisation and secondary actions out of the header, keeping the panel toggle and its badge', async () => {
        const props = { thread: thread({ client_id: 3 }), canLinkClient: true, canExport: true, followup: true, canBlock: true, canAssign: true };
        const plain = mount(ThreadHeader, { props });
        expect(plain.text()).toContain('المواضيع');
        expect(plain.find('[data-test="open-export"]').exists()).toBe(true);
        expect(plain.find('[data-test="panel-toggle"]').exists()).toBe(false);

        const w = mount(ThreadHeader, { props: { ...props, panel: true, panelBadge: '✈️ يسافر غداً' } });
        expect(w.text()).not.toContain('المواضيع');
        expect(w.text()).not.toContain('الموكل إليه');
        expect(w.find('[data-test="open-export"]').exists()).toBe(false);
        expect(w.find('[data-test="block-btn"]').exists()).toBe(false);
        expect(w.find('[data-test="panel-badge"]').text()).toBe('✈️ يسافر غداً');
        await w.find('[data-test="panel-toggle"]').trigger('click');
        expect(w.emitted('togglePanel')).toHaveLength(1);
    });

    it('keeps the block button in the header during a rate-limit attention', () => {
        const w = mount(ThreadHeader, { props: { thread: thread({ needs_attention: true, attention_reason: 'rate_limit' }), canBlock: true, panel: true } });
        expect(w.find('[data-test="block-btn"]').exists()).toBe(true);
    });
});

describe('ThreadView with the panel', () => {
    afterEach(() => vi.restoreAllMocks());

    it('opens the panel as a drawer on narrow screens and hides the old fact strip', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: body() });
        const w = mount(ThreadView, { props: { thread: thread(), flags: { conversation_panel: true, thread_pins: true } }, attachTo: document.body });
        await flushPromises();
        expect(w.find('[data-test="fact-card"]').exists()).toBe(false);
        const drawer = document.querySelector('[data-test="panel-drawer"]');
        expect(drawer.style.display).toBe('none');
        await w.find('[data-test="panel-toggle"]').trigger('click');
        expect(drawer.style.display).toBe('');
        expect(w.find('[data-test="panel-badge"]').text()).toContain('3 أيام');
        drawer.querySelector('[data-test="panel-close"]').click();
        await flushPromises();
        expect(drawer.style.display).toBe('none');
        w.unmount();
    });
});
