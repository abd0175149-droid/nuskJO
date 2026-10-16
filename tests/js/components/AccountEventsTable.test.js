import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AccountEventsTable from '@/Components/WhatsApp/AccountEventsTable.vue';

const events = [
    { id: 2, at: '2026-10-16T09:00:00Z', field: 'security', event: 'PIN_RESET_REQUEST', severity: 'critical', effect: 'alert', handled: true },
    { id: 1, at: '2026-10-16T08:00:00Z', field: 'account_update', event: 'DISABLED_UPDATE', severity: 'critical', effect: 'ignored_foreign', handled: true },
];

describe('AccountEventsTable', () => {
    afterEach(() => vi.restoreAllMocks());

    it('lists events with Arabic field, severity and effect, in Amman time', () => {
        const w = mount(AccountEventsTable, { props: { events } });
        expect(w.text()).toContain('الأمان');
        expect(w.text()).toContain('حرج');
        expect(w.text()).toContain('لحساب أو رقم آخر');
        expect(w.text()).toContain('16/10/2026, 12:00');
    });

    it('loads one payload on demand, and offers none for another account', async () => {
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { id: 2, payload: { event: 'PIN_RESET_REQUEST' }, created_at: '2026-10-16T09:00:00Z' } });
        const w = mount(AccountEventsTable, { props: { events }, attachTo: document.body });

        expect(w.findAll('li button')).toHaveLength(1);
        await w.find('li button').trigger('click');
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/api/whatsapp/channel-health/events/2', expect.anything());
        expect(w.find('pre').text()).toContain('"event": "PIN_RESET_REQUEST"');
        w.unmount();
    });

    it('says when there are no events', () => {
        expect(mount(AccountEventsTable, { props: { events: [] } }).text()).toContain('لا أحداث حساب بعد');
    });
});
