import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ConversationalComponentsPanel from '@/Components/WhatsApp/ConversationalComponentsPanel.vue';

const initial = (over = {}) => ({
    enabled: true,
    desired: { prompts: ['أين موقع مكتبكم؟'], commands: [{ command_name: 'الموقع', command_description: 'عنوان المكتب' }] },
    synced_at: null,
    last_snapshot: null,
    last_error: null,
    linked: { prompts: [false], commands: [true] },
    ...over,
});

describe('ConversationalComponentsPanel (E37)', () => {
    afterEach(() => vi.restoreAllMocks());

    it('shows four prompt fields with counters and the rule link of what was saved', () => {
        const w = mount(ConversationalComponentsPanel, { props: { initial: initial() } });
        expect(w.findAll('input[aria-label^="السؤال"]')).toHaveLength(4);
        expect(w.text()).toContain(`${'أين موقع مكتبكم؟'.length}/80`);
        expect(w.text()).toContain('مربوط بقاعدة');
        expect(w.text()).toContain('بلا قاعدة');
    });

    it('syncs the non-empty lists after confirmation and shows the warnings', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true, warnings: ['«أين موقع مكتبكم؟» بلا قاعدة'], state: initial({ synced_at: '2026-10-16T09:00:00+03:00' }) } });
        const w = mount(ConversationalComponentsPanel, { props: { initial: initial() } });

        await w.find('[data-test="sync"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/conversational-automation/sync', {
            prompts: ['أين موقع مكتبكم؟'],
            commands: [{ command_name: 'الموقع', command_description: 'عنوان المكتب' }],
        });
        expect(w.find('[data-test="message"]').text()).toContain('زُومنت');
        expect(w.find('[data-test="warnings"]').text()).toContain('بلا قاعدة');
    });

    it('does nothing when the confirmation is declined', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        const post = vi.spyOn(axios, 'post');
        const w = mount(ConversationalComponentsPanel, { props: { initial: initial() } });
        await w.find('[data-test="clear"]').trigger('click');
        await flushPromises();
        expect(post).not.toHaveBeenCalled();
    });

    it('clearing sends empty lists', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true, warnings: [], state: initial({ desired: { prompts: [], commands: [] } }) } });
        const w = mount(ConversationalComponentsPanel, { props: { initial: initial() } });
        await w.find('[data-test="clear"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/api/whatsapp/conversational-automation/sync', { prompts: [], commands: [] });
        expect(w.findAll('input[aria-label^="اسم الأمر"]')).toHaveLength(0);
    });

    it('shows field errors from the server and the stored Meta error code', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 422, data: { message: 'x', errors: { 'prompts.0': ['لا إيموجي في الأسئلة والأوامر (شرط ميتا).'] } } } });
        const w = mount(ConversationalComponentsPanel, { props: { initial: initial({ last_error: { code: 'BAD_REQUEST', meta_code: 100, fbtrace_id: 'T1' } }) } });
        expect(w.find('[data-test="last-error"]').text()).toContain('BAD_REQUEST');
        await w.find('[data-test="sync"]').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('لا إيموجي');
    });

    it('compares what WhatsApp shows with what was saved', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: { ok: true, same: false, live: { prompts: ['سؤال قديم'], commands: [] }, state: initial() } });
        const w = mount(ConversationalComponentsPanel, { props: { initial: initial() } });
        await w.find('[data-test="read"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-test="live"]').text()).toContain('يختلف عن المحفوظ');
        expect(w.find('[data-test="live"]').text()).toContain('سؤال قديم');
    });

    it('is disabled while the flag is off', () => {
        const w = mount(ConversationalComponentsPanel, { props: { initial: initial({ enabled: false }) } });
        expect(w.find('[data-test="components-off"]').exists()).toBe(true);
        expect(w.find('[data-test="sync"]').attributes('disabled')).toBeDefined();
    });
});
