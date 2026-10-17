// الردود الجاهزة في المنشئ (E17): متغير {{…}} لم يُملأ يمنع الإرسال هنا، والخادم يرفضه أيضاً (UNFILLED_VARIABLE).
// «{name}» بقوس واحد ليس متغيراً.
const PLACEHOLDER = /\{\{\s*([\p{L}\p{N}_]+)\s*\}\}/gu;

/** @returns {string[]} أسماء المتغيرات الباقية بلا قيمة، بلا تكرار */
export function unfilledVariables(text) {
    return [...new Set([...String(text || '').matchAll(PLACEHOLDER)].map((m) => m[1]))];
}

export function hasUnfilled(text) {
    return unfilledVariables(text).length > 0;
}

/** يدرج الرد في المسودة: يحلّ محلها إن كانت فارغة (أو «/» وحدها)، وإلا يُلحق بسطر جديد */
export function insertReply(draft, text) {
    const current = String(draft || '');
    if (current.trim() === '' || current.trim() === '/') return text;
    return current.replace(/\s+$/u, '') + '\n' + text;
}

/**
 * القائمة مجمّعة بالتصنيف بترتيب التصنيفات، مع بحث في العنوان والنص.
 * @returns {Array<{key: string, label: string, items: object[]}>}
 */
export function groupReplies(replies, categories, query = '') {
    const q = String(query || '').trim().toLowerCase();
    const match = (r) => !q || `${r.title} ${r.body}`.toLowerCase().includes(q);
    return Object.entries(categories || {})
        .map(([key, label]) => ({ key, label, items: (replies || []).filter((r) => r.category === key && match(r)) }))
        .filter((g) => g.items.length);
}
