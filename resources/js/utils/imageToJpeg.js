// تحويل صورة إلى JPEG في المتصفح (E32.10): HEIC وWebP (لا يقبلهما واتساب ولا gd في الحاوية)، والصورة الأكبر من حدها.
// createImageBitmap ثم canvas بأقصى بُعد 2560 وخلفية بيضاء (الشفافية لا تصير سواداً) ثم toBlob('image/jpeg', 0.9).
// يرمي إن لم يفك المتصفح الصورة (HEIC على Chrome في ويندوز مثلاً)، والمستدعي يشرح للموظف.

export const MAX_DIMENSION = 2560;

/** الاسم نفسه بامتداد .jpg */
export function jpgName(name) {
    const base = String(name || '').replace(/\.[^./\\]+$/, '').trim();
    return `${base || 'صورة'}.jpg`;
}

/**
 * @param {File|Blob} file
 * @param {{maxDimension?: number, quality?: number}} [options]
 * @returns {Promise<File>}
 */
export async function imageToJpeg(file, { maxDimension = MAX_DIMENSION, quality = 0.9 } = {}) {
    if (typeof globalThis.createImageBitmap !== 'function') throw new Error('createImageBitmap unavailable');
    const bitmap = await globalThis.createImageBitmap(file);
    try {
        const scale = Math.min(1, maxDimension / Math.max(bitmap.width || 1, bitmap.height || 1));
        const width = Math.max(1, Math.round(bitmap.width * scale));
        const height = Math.max(1, Math.round(bitmap.height * scale));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('canvas unavailable');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, width, height);
        ctx.drawImage(bitmap, 0, 0, width, height);
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('encode failed'))), 'image/jpeg', quality);
        });
        return new File([blob], jpgName(file.name), { type: 'image/jpeg', lastModified: Date.now() });
    } finally {
        bitmap.close?.();
    }
}
