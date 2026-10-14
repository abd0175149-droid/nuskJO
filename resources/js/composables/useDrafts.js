import { reactive } from 'vue';

// مسودات الرد لكل محادثة على حدة، حتى لا يُرسل نص كُتب لعميل إلى عميل آخر.
// تُنسخ إلى sessionStorage فقط (لا localStorage) فلا تبقى على جهاز مشترك بعد إغلاق التبويب.
export const DRAFTS_KEY = 'wa:drafts:v1';
const MAX_LEN = 4000;
const MAX_DRAFTS = 30;

const drafts = reactive(new Map());
let loaded = false;

function restore() {
    if (loaded) return;
    loaded = true;
    try {
        const raw = sessionStorage.getItem(DRAFTS_KEY);
        if (!raw) return;
        const saved = JSON.parse(raw);
        if (!saved || typeof saved !== 'object') return;
        Object.entries(saved).slice(-MAX_DRAFTS).forEach(([id, text]) => {
            if (typeof text === 'string' && text) drafts.set(String(id), text.slice(0, MAX_LEN));
        });
    } catch (e) { /* التخزين غير متاح: نعمل من الذاكرة فقط */ }
}

function persist() {
    try {
        const entries = [...drafts.entries()].filter(([, text]) => text).slice(-MAX_DRAFTS);
        if (entries.length) sessionStorage.setItem(DRAFTS_KEY, JSON.stringify(Object.fromEntries(entries)));
        else sessionStorage.removeItem(DRAFTS_KEY);
    } catch (e) { /* تجاهل */ }
}

export function clearStoredDrafts() {
    drafts.clear();
    try { sessionStorage.removeItem(DRAFTS_KEY); } catch (e) { /* تجاهل */ }
}

export function useDrafts() {
    restore();

    const get = (id) => (id == null ? '' : drafts.get(String(id)) || '');

    const set = (id, text) => {
        if (id == null) return;
        const key = String(id);
        const value = String(text ?? '').slice(0, MAX_LEN);
        drafts.delete(key); // يعيد المفتاح إلى آخر الترتيب ليُحفظ الأحدث عند تجاوز الحد
        if (value) drafts.set(key, value);
        persist();
    };

    const clear = (id) => {
        if (id == null) return;
        drafts.delete(String(id));
        persist();
    };

    return { get, set, clear, clearAll: clearStoredDrafts };
}
