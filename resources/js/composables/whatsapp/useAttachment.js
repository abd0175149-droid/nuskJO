// المرفق قبل الرفع (E32.10): النوع من file.type ثم الامتداد، والحد لكل نوع من سياسة الخادم (mediaPolicy)، ورسالة عربية
// فورية بلا طلب. HEIC وWebP تُحوَّل إلى JPEG، والصورة الأكبر من حدها تُصغَّر مرة واحدة. الخادم يفحص كل شيء من جديد بـ finfo.
import { imageToJpeg } from '@/utils/imageToJpeg.js';

const CONVERT_TYPES = ['image/heic', 'image/heif', 'image/webp'];
const CONVERT_EXTS = ['heic', 'heif', 'webp'];

export function extOf(name) {
    const m = /\.([A-Za-z0-9]{1,8})$/.exec(String(name || ''));
    return m ? m[1].toLowerCase() : '';
}

export function formatMb(bytes) {
    return `${Math.floor(Number(bytes) / (1024 * 1024))}MB`;
}

/** مواصفة النوع من السياسة: بالنوع المعلن ثم بالامتداد. null: غير مسموح */
export function specFor(file, policy) {
    const types = policy?.types || [];
    const type = String(file?.type || '').toLowerCase();
    const ext = extOf(file?.name);
    return types.find((t) => t.mime === type) || types.find((t) => (t.exts || []).includes(ext)) || null;
}

export function needsConversion(file) {
    return CONVERT_TYPES.includes(String(file?.type || '').toLowerCase()) || CONVERT_EXTS.includes(extOf(file?.name));
}

function allowedLabel(policy) {
    const kinds = new Set((policy?.types || []).map((t) => t.kind));
    return [kinds.has('image') && 'صورة JPG أو PNG', kinds.has('document') && 'PDF', kinds.has('video') && 'فيديو MP4'].filter(Boolean).join(' و');
}

/**
 * @returns {Promise<{ok: true, file: File, kind: string, mime: string, converted: boolean} | {ok: false, error: string}>}
 */
export async function prepareAttachment(file, policy, convert = imageToJpeg) {
    if (!file) return { ok: false, error: 'لم يُختر ملف.' };
    let current = file;
    let converted = false;
    if (needsConversion(current)) {
        try {
            current = await convert(current);
            converted = true;
        } catch {
            return { ok: false, error: 'تعذّر تحويل الصورة في المتصفح — حوّل الصورة إلى JPG من هاتفك أو إلى PDF ثم أرسلها.' };
        }
    }

    const spec = specFor(current, policy);
    if (!spec) return { ok: false, error: `نوع الملف غير مسموح. المسموح: ${allowedLabel(policy)}.` };
    if (current.size <= 0) return { ok: false, error: 'الملف فارغ.' };

    if (current.size > spec.max) {
        if (spec.kind !== 'image' || converted) {
            return { ok: false, error: spec.kind === 'image'
                ? `الصورة أكبر من ${formatMb(spec.max)} — صغّرها أو حوّلها إلى PDF.`
                : spec.kind === 'video' ? `الفيديو أكبر من ${formatMb(spec.max)} — قصّه أو أرسله رابطاً.` : `الملف أكبر من ${formatMb(spec.max)} المسموح بها.` };
        }
        // تصغير مرة واحدة إلى JPEG
        try {
            current = await convert(current);
            converted = true;
        } catch {
            return { ok: false, error: `الصورة أكبر من ${formatMb(spec.max)} — صغّرها أو حوّلها إلى PDF.` };
        }
        const jpeg = specFor(current, policy);
        if (!jpeg || current.size > jpeg.max) return { ok: false, error: `الصورة أكبر من ${formatMb(spec.max)} — صغّرها أو حوّلها إلى PDF.` };
        return { ok: true, file: current, kind: jpeg.kind, mime: jpeg.mime, converted };
    }

    return { ok: true, file: current, kind: spec.kind, mime: spec.mime, converted };
}
