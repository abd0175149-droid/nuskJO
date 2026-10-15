import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import InboxStatusBar from '@/Components/WhatsApp/InboxStatusBar.vue';

const base = { status: { channel_ready: true, bot_enabled: true }, llm: { state: 'closed' }, staleSince: '', notice: '' };

describe('InboxStatusBar', () => {
    it('shows the refresh-failed strip instead of swallowing the error, and hides it on success', async () => {
        const w = mount(InboxStatusBar, { props: { ...base, staleSince: '10:42' } });
        expect(w.find('[role="status"]').text()).toContain('تعذّر التحديث منذ 10:42');
        await w.setProps({ staleSince: '' });
        expect(w.find('[role="status"]').exists()).toBe(false);
    });

    it('shows the out-of-scope notice and lets the user dismiss it', async () => {
        const w = mount(InboxStatusBar, { props: { ...base, notice: 'هذه المحادثة غير متاحة لك' } });
        expect(w.text()).toContain('غير متاحة لك');
        await w.find('button[aria-label="إغلاق التنبيه"]').trigger('click');
        expect(w.emitted('dismiss')).toHaveLength(1);
    });

    it('announces handover mode while the AI provider is down', () => {
        const w = mount(InboxStatusBar, { props: { ...base, llm: { state: 'open' } } });
        expect(w.text()).toContain('وضع التحويل');
    });
});
