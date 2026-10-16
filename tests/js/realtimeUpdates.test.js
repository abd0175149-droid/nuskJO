import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn() } }));
const { router } = await import('@inertiajs/vue3');
const { useRealtimeUpdates } = await import('@/composables/useRealtimeUpdates.js');

// تحديثات البيانات على القناة الخاصة staff (E28)، والإزالة تزيل مستمع الصفحة وحده
describe('useRealtimeUpdates', () => {
    afterEach(() => { delete window.Echo; });

    it('listens on the private staff channel and only stops its own listener', () => {
        const listeners = {};
        const channel = {
            listen: vi.fn((e, f) => { listeners[e] = f; return channel; }),
            stopListening: vi.fn(() => channel),
        };
        window.Echo = { private: vi.fn(() => channel), channel: vi.fn(), leave: vi.fn() };
        const Page = defineComponent({ setup() { useRealtimeUpdates(['agent']); return () => h('div'); } });

        const w = mount(Page);
        expect(window.Echo.private).toHaveBeenCalledWith('staff');
        expect(window.Echo.channel).not.toHaveBeenCalled();

        listeners['.data.updated']({ entity: 'client', action: 'created', entityId: 1 });
        expect(router.reload).not.toHaveBeenCalled();
        listeners['.data.updated']({ entity: 'agent', action: 'created', entityId: 2 });
        expect(router.reload).toHaveBeenCalledTimes(1);

        w.unmount();
        expect(channel.stopListening).toHaveBeenCalledWith('.data.updated', listeners['.data.updated']);
        expect(window.Echo.leave).not.toHaveBeenCalled();
    });
});
