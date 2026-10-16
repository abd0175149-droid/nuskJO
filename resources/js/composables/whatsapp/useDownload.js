// تنزيل ملف من مسار GET محمي (تصدير المحادثة، E30.8): الطلب بـ axios فتظهر أخطاء 422 و429 داخل النافذة بدل صفحة
// خطأ، والملف يُحفظ باسمه من الخادم (Content-Disposition). لا يبقى الرابط بمعاملاته في سجل المتصفح.
import axios from 'axios';

/** اسم الملف من الترويسة، أو البديل */
export function fileNameFrom(disposition, fallback) {
    const star = /filename\*\s*=\s*(?:UTF-8'')?([^;]+)/i.exec(disposition || '');
    if (star) {
        try { return decodeURIComponent(star[1].trim().replace(/^"|"$/g, '')); } catch { /* يُجرَّب الشكل العادي */ }
    }
    const plain = /filename\s*=\s*"?([^";]+)"?/i.exec(disposition || '');
    return plain ? plain[1].trim() : fallback;
}

/** جسم خطأ وصل Blob (responseType: blob) → كائن JSON */
export async function errorBody(e) {
    const data = e?.response?.data;
    if (data && typeof data.text === 'function') {
        try { return JSON.parse(await data.text()); } catch { return {}; }
    }
    return data && typeof data === 'object' ? data : {};
}

export function saveBlob(blob, name, doc = globalThis.document) {
    const url = URL.createObjectURL(blob);
    const a = doc.createElement('a');
    a.href = url;
    a.download = name;
    a.rel = 'noopener';
    doc.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** @returns {Promise<string>} اسم الملف المحفوظ */
export async function download(url, params, { get = (u, c) => axios.get(u, c), fallbackName = 'download', save = saveBlob } = {}) {
    const res = await get(url, { params, responseType: 'blob', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    const name = fileNameFrom(res.headers?.['content-disposition'], fallbackName);
    save(res.data, name);
    return name;
}
