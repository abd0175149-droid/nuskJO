import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({ default: { post: vi.fn(), delete: vi.fn() } }));

import axios from 'axios';
import McpTokensPanel from '@/Components/Integrations/McpTokensPanel.vue';
import { confirmState, settle } from '@/composables/useConfirm';

// رموز MCP (E40.9): الرمز يُعرض مرة واحدة مع ملف .mcp.json، والمدة حتى الحد، والإبطال بعد تأكيد
const mounted = [];
const make = (props = {}) => {
    const w = mount(McpTokensPanel, { props: { tokens: [], maxDays: 30, url: 'https://nusk.example.org/mcp/owner', cloudflare: true, ...props }, attachTo: document.body });
    mounted.push(w);
    return w;
};

beforeEach(() => { axios.post.mockReset(); axios.delete.mockReset(); confirmState.hosts = ['test']; });
afterEach(() => { while (mounted.length) mounted.pop().unmount(); document.body.innerHTML = ''; confirmState.hosts = []; });

describe('McpTokensPanel', () => {
    it('warns while Cloudflare Access is not configured', () => {
        expect(make({ cloudflare: false }).find('[data-test="no-cloudflare"]').exists()).toBe(true);
        expect(make().find('[data-test="no-cloudflare"]').exists()).toBe(false);
    });

    it('creates a token, shows it once with the .mcp.json, and lists it', async () => {
        axios.post.mockResolvedValue({ data: { token: '9|njmcp_secretvalue', item: { id: 9, name: 'المكتب', last_used_at: null, expires_at: '2026-10-23T10:00:00+03:00', expired: false } } });
        const w = make();
        expect(w.findAll('#mcp-days option').map((o) => o.element.value)).toEqual(['1', '7', '14', '30']);
        await w.find('#mcp-name').setValue('المكتب');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/settings/integrations/mcp-tokens', { name: 'المكتب', days: 7 });
        expect(w.find('[data-test="mcp-config"]').text()).toContain('Bearer 9|njmcp_secretvalue');
        expect(w.findAll('[data-test="tokens"] li')).toHaveLength(1);
        expect(w.text()).toContain('يظهر مرة واحدة');
    });

    it('revokes after confirmation', async () => {
        axios.delete.mockResolvedValue({ data: { ok: true } });
        const w = make({ tokens: [{ id: 4, name: 'قديم', expired: false }] });
        await w.find('[data-test="tokens"] button').trigger('click');
        expect(confirmState.current.title).toContain('قديم');
        settle(true);
        await flushPromises();

        expect(axios.delete).toHaveBeenCalledWith('/settings/integrations/mcp-tokens/4');
        expect(w.find('[data-test="tokens"]').exists()).toBe(false);
    });
});
