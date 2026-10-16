// التعافي من ملفات JS القديمة بعد النشر (E28): إعادة تحميل واحدة على الأكثر كل 30 ثانية.
// الحارس في sessionStorage، وإن حُجب ففي window.name (يبقى مع إعادة التحميل في التبويب نفسه) ثم في الذاكرة،
// فلا حلقة إعادة تحميل أبداً. المفتاح بلا بيانات شخصية.

const KEY = 'nuskjo:reload-at';
const NAME = /^nuskjo:reload-at:(\d+)$/;
export const RELOAD_GUARD_MS = 30000;

function guardStorage(win, now) {
    try {
        const last = win.sessionStorage.getItem(KEY);
        if (last && now - Number(last) < RELOAD_GUARD_MS) return false;
        win.sessionStorage.setItem(KEY, String(now));
        return true;
    } catch {
        return null;
    }
}

function guardFallback(win, now) {
    try {
        const m = NAME.exec(String(win.name || ''));
        if (m && now - Number(m[1]) < RELOAD_GUARD_MS) return false;
        if (!win.name || m) {
            win.name = `nuskjo:reload-at:${now}`;
            return true;
        }
    } catch {
        // window.name غير متاح: الذاكرة وحدها
    }
    if (win.__nuskjoReloaded) return false;
    win.__nuskjoReloaded = true;
    return true;
}

/** يعيد التحميل ويعيد true، أو false إن أُعيد التحميل قبل أقل من 30 ثانية. reason للتشخيص فقط */
export function reloadOnce(reason, { win = window, now = Date.now() } = {}) {
    const allowed = guardStorage(win, now) ?? guardFallback(win, now);
    if (!allowed) return false;
    win.location.reload();
    return true;
}

// نماذج فيها تعديل غير محفوظ (AppModal بـ dirty، أو صفحة تضبط window.__nuskjoDirtyForm): لا إعادة تحميل تلقائية فوقها
const dirtyForms = new Set();

export function setDirtyForm(id, dirty) {
    if (dirty) dirtyForms.add(id);
    else dirtyForms.delete(id);
}

export function hasDirtyForm(win = window) {
    return dirtyForms.size > 0 || !!win.__nuskjoDirtyForm;
}

/** فشل تحميل جزء ديناميكي (ملف حُذف بعد النشر)، لا خطأ في الصفحة نفسها */
export function isChunkLoadError(error) {
    const text = String(error?.message || error || '');
    return /dynamically imported module|Importing a module script failed|error loading dynamically imported module|Unable to preload CSS|ChunkLoadError/i.test(text);
}
