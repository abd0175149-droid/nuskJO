import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useInbox } from '@/composables/whatsapp/useInbox.js';

// زر البوت يرسل القيمة المطلوبة صراحة (E21): نقرتان على حالة قديمة لا تعكسان النتيجة
describe('toggleBot', () => {
    afterEach(() => vi.restoreAllMocks());

    it('posts the wanted state and keeps what the server answered', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { bot_enabled: false } });
        vi.spyOn(axios, 'get').mockResolvedValue({ data: { conversations: [] } });
        const inbox = useInbox({ conversations: [] }, () => null);
        inbox.s.activeId = 7;
        inbox.s.thread = { id: 7, bot_enabled: true };

        await inbox.toggleBot();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/conversations/7/toggle-bot', { enabled: false }, expect.anything());
        expect(inbox.s.thread.bot_enabled).toBe(false);

        post.mockResolvedValue({ data: { bot_enabled: true } });
        await inbox.toggleBot();
        expect(post).toHaveBeenLastCalledWith('/api/whatsapp/conversations/7/toggle-bot', { enabled: true }, expect.anything());
    });
});
