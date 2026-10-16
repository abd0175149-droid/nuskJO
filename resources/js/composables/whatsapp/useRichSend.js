// نداءات الرسائل الغنية من الخيط (E33): التفاعل والتصحيح والدبوس والبطاقات. كل نداء بمفتاح Idempotency-Key جديد،
// والخادم يعيد كل الفحوص (الصلاحية والسياج والعلم والنافذة والقائمة المعتمدة). لا إعادة آلية بعد فشل.
import axios from 'axios';
import { uuid } from '@/composables/whatsapp/useSendKey.js';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const base = (id) => `/api/whatsapp/conversations/${id}`;

async function post(url, body, fallback) {
    try {
        const { data } = await axios.post(url, body, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Idempotency-Key': uuid() },
        });
        return { ok: !!data?.ok, data, error: data?.ok ? '' : (data?.error || fallback) };
    } catch (e) {
        return { ok: false, data: e?.response?.data || {}, status: e?.response?.status, error: errorText(e, fallback) };
    }
}

export const react = (conversationId, messageId, emoji) =>
    post(`${base(conversationId)}/messages/${messageId}/react`, { emoji }, 'تعذّر إرسال التفاعل');

export const correct = (conversationId, messageId, body, ackWarnings = false) =>
    post(`${base(conversationId)}/messages/${messageId}/correct`, { body, ...(ackWarnings ? { ack_warnings: true } : {}) }, 'تعذّر إرسال التصحيح');

export const sendLocation = (conversationId, placeId, replyToId = null) =>
    post(`${base(conversationId)}/send-location`, { place_id: placeId, ...(replyToId ? { reply_to_id: replyToId } : {}) }, 'تعذّر إرسال الموقع');

export const sendContacts = (conversationId, cardIds, replyToId = null) =>
    post(`${base(conversationId)}/send-contact`, { card_ids: cardIds, ...(replyToId ? { reply_to_id: replyToId } : {}) }, 'تعذّر إرسال البطاقة');

/** هل في النص رابط http أو https (تظهر خانة المعاينة) */
export const hasLink = (text) => /https?:\/\/\S/i.test(String(text ?? ''));
