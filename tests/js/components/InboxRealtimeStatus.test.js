import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import InboxStatusBar from '@/Components/WhatsApp/InboxStatusBar.vue';

const base = { status: { channel_ready: true }, llm: { state: 'closed' } };

// حالة المقبس للصندوق في شريطه (E28)، لا في شريط عام ثانٍ
describe('InboxStatusBar realtime state', () => {
    it('says live updates are paused only when the socket is down', async () => {
        const w = mount(InboxStatusBar, { props: { ...base, realtime: 'unavailable' } });
        expect(w.find('[data-test="realtime-off"]').text()).toContain('التحديث اللحظي متوقف');

        for (const state of ['connected', 'connecting', 'disabled']) {
            await w.setProps({ realtime: state });
            expect(w.find('[data-test="realtime-off"]').exists()).toBe(false);
        }
        await w.setProps({ realtime: 'failed' });
        expect(w.find('[data-test="realtime-off"]').exists()).toBe(true);
    });
});
