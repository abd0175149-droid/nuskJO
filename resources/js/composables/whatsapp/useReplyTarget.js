// الرسالة التي يرد عليها الموظف (E33): واحدة لكل محادثة، بمعرّفنا الداخلي لا wamid. الخادم يحلّها داخل المحادثة نفسها
// ويرفض غيرها، فالمقتطف هنا للعرض وحده. تُلغى بـ ✕ أو Esc، وبعد إرسال ناجح.
import { reactive } from 'vue';

export const SNIPPET_MAX = 80;

/** مقتطف عرض: سطر واحد حتى 80 حرفاً (بالمحارف لا البايتات) */
export function snippetOf(m) {
    const text = String(m?.body ?? '').replace(/\s+/g, ' ').trim();
    const chars = [...text];
    return chars.length > SNIPPET_MAX ? `${chars.slice(0, SNIPPET_MAX).join('')}…` : text;
}

export function createReplyTarget() {
    const byConversation = reactive({});
    return {
        /** @returns {{id: number, snippet: string, direction: string}|null} */
        get(conversationId) {
            return byConversation[conversationId] ?? null;
        },
        set(conversationId, m) {
            if (!conversationId || !m?.id || !m.can_quote) return false;
            byConversation[conversationId] = { id: Number(m.id), snippet: snippetOf(m), direction: m.direction };
            return true;
        },
        clear(conversationId) {
            delete byConversation[conversationId];
        },
        /** ما يُرسل مع الرد: reply_to_id أو لا شيء */
        payload(conversationId) {
            const t = byConversation[conversationId];
            return t ? { reply_to_id: t.id } : {};
        },
    };
}
