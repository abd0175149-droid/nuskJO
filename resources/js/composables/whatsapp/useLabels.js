import axios from 'axios';

// تصنيفات المحادثات وشارات النظام (E29): ألوان من خريطة ثابتة بأصناف Tailwind كاملة، فلا صنف يُبنى من نص،
// والمعطّل يبقى ظاهراً باهتاً على المحادثات القديمة. المفاتيح من WaLabel::COLORS في الخادم.
export const LABEL_COLORS = {
    gray: 'bg-gray-100 text-gray-700 ring-gray-300 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600',
    red: 'bg-red-100 text-red-700 ring-red-300 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-800',
    amber: 'bg-amber-100 text-amber-800 ring-amber-300 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800',
    green: 'bg-green-100 text-green-700 ring-green-300 dark:bg-green-900/30 dark:text-green-300 dark:ring-green-800',
    blue: 'bg-blue-100 text-blue-700 ring-blue-300 dark:bg-blue-900/30 dark:text-blue-300 dark:ring-blue-800',
    purple: 'bg-purple-100 text-purple-700 ring-purple-300 dark:bg-purple-900/30 dark:text-purple-300 dark:ring-purple-800',
    gold: 'bg-gold-100 text-gold-800 ring-gold-300 dark:bg-gold-900/30 dark:text-gold-300 dark:ring-gold-700',
};

/** دوائر اختيار اللون في صفحة الإدارة */
export const LABEL_SWATCHES = {
    gray: 'bg-gray-400', red: 'bg-red-500', amber: 'bg-amber-500', green: 'bg-green-500',
    blue: 'bg-blue-500', purple: 'bg-purple-500', gold: 'bg-gold-500',
};

export const COLOR_NAMES = { gray: 'رمادي', red: 'أحمر', amber: 'كهرماني', green: 'أخضر', blue: 'أزرق', purple: 'بنفسجي', gold: 'ذهبي' };

/** صنف الشريحة: لون معروف وإلا رمادي، والمعطّل باهت */
export function labelClass(color, active = true) {
    return `${LABEL_COLORS[color] || LABEL_COLORS.gray}${active === false ? ' opacity-60' : ''}`;
}

// شارات النظام (ConversationBadges): أيقونة لكل مفتاح، والنص من الخادم. لون محايد للمالية بلا مبالغ
export const BADGE_ICONS = { quote: '💰', trip: '✈️', invoice_pending: '🧾', balance: '💳' };

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };

/** وضع تصنيف على محادثة أو إزالته؛ يعيد شرائح المحادثة بعد التعديل */
export async function setLabel(conversationId, labelId, on) {
    const url = `/api/whatsapp/conversations/${conversationId}/labels/${labelId}`;
    const { data } = on ? await axios.post(url, {}, api) : await axios.delete(url, api);
    return data.labels || [];
}

/** اسم اللغة بالعربية من رمز ISO 639-1، وإلا الرمز نفسه */
export function languageName(code) {
    if (!code) return '';
    try {
        return new Intl.DisplayNames(['ar'], { type: 'language' }).of(code) || code;
    } catch {
        return code;
    }
}
