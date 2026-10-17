import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LabelPicker from '@/Components/WhatsApp/LabelPicker.vue';
import ConversationChips from '@/Components/WhatsApp/ConversationChips.vue';

const labels = Array.from({ length: 6 }, (_, i) => ({ id: i + 1, name: `تصنيف ${i + 1}`, color: ['red', 'amber', 'green', 'blue', 'purple', 'gold'][i] }));
const chip = (l, active = true) => ({ ...l, active });

describe('LabelPicker (E29)', () => {
    afterEach(() => vi.restoreAllMocks());

    it('offers the active list only, with no free-text field', () => {
        const w = mount(LabelPicker, { props: { open: true, conversationId: 7, labels } });
        expect(w.findAll('input[type="checkbox"]')).toHaveLength(6);
        expect(w.find('input[type="text"], input:not([type]), textarea').exists()).toBe(false);
        expect(w.text()).toContain('لا يراها العميل');
        expect(w.find('a[href="/whatsapp/labels"]').exists()).toBe(false);
        expect(mount(LabelPicker, { props: { open: true, conversationId: 7, labels, canManage: true } }).find('a[href="/whatsapp/labels"]').exists()).toBe(true);
    });

    it('stops at five: the unchecked boxes are disabled', () => {
        const selected = labels.slice(0, 5).map((l) => chip(l));
        const w = mount(LabelPicker, { props: { open: true, conversationId: 7, labels, selected } });
        expect(w.find('[data-test="label-6"]').element.disabled).toBe(true);
        expect(w.find('[data-test="label-1"]').element.disabled).toBe(false);
        expect(w.text()).toContain('بلغت الحد');
    });

    it('attaches through the API and returns the new chips', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { labels: [chip(labels[1])] } });
        const w = mount(LabelPicker, { props: { open: true, conversationId: 7, labels } });
        await w.find('[data-test="label-2"]').setValue(true);
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/api/whatsapp/conversations/7/labels/2', {}, expect.anything());
        expect(w.emitted('changed')[0][0]).toEqual([chip(labels[1])]);
    });
});

describe('ConversationChips (E29)', () => {
    it('draws colours from the fixed map, fades inactive labels and shows badges without amounts', () => {
        const w = mount(ConversationChips, { props: {
            labels: [{ id: 1, name: 'VIP', color: 'gold', active: true }, { id: 2, name: 'قديم', color: 'unknown<script>', active: false }],
            badges: [{ key: 'balance', label: 'عليه رصيد' }, { key: 'trip', label: 'سفر بعد 3 أيام' }],
        } });
        const [vip, old] = w.findAll('[data-test="label-chip"]');
        expect(vip.classes()).toContain('bg-gold-100');
        expect(old.classes()).toContain('bg-gray-100');
        expect(old.classes()).toContain('opacity-60');
        expect(w.html()).not.toContain('unknown');
        expect(w.findAll('[data-test="badge"]').map((b) => b.text())).toEqual(['💳عليه رصيد', '✈️سفر بعد 3 أيام']);
    });

    it('renders nothing without labels or badges', () => {
        expect(mount(ConversationChips).find('[data-test="conversation-chips"]').exists()).toBe(false);
    });
});
