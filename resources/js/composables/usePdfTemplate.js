// قالب الطباعة (PDF المرفوع من الإعدادات) خلفيةً لصفحات الطباعة (E26.2).
// pdf.js من حزمتنا لا من شبكة توزيع خارجية: لا سكربت من طرف ثالث وقت التشغيل، والعامل من /build/assets
// على الأصل نفسه (worker-src 'self'). وisEvalSupported:false دائماً، فلا يُنفَّذ كود من خط PDF مصنوع
// (CVE-2024-4367) حتى لو رجعنا يوماً إلى إصدار أقدم.
// البناء legacy كما في عارض المرفقات (E14): الحديث يستعمل دوالّ JS لم تصل بعد إلى كل المتصفحات.

/** خيارات ثابتة لكل مستند: بلا eval وبلا نماذج XFA */
export const PDF_OPTIONS = Object.freeze({ isEvalSupported: false, enableXfa: false });

// إصدار رابط العامل: الملف نفسه خرج من nginx بنوع octet-stream حتى 2026-10-18 بكاش سنة، فالمتصفح يحتفظ بالرد
// الخاطئ ويرفض تشغيله. رقم جديد هنا يجبر كل متصفح على جلب العامل من جديد، فيُرفع عند أي إصلاح مماثل
export const WORKER_REV = '2';
export const workerUrl = (url) => `${url}${String(url).includes('?') ? '&' : '?'}v=${WORKER_REV}`;

let loading = null;

/** تحميل كسول: pdf.js لا يدخل الحزمة الرئيسية، ويُحمَّل مرة واحدة لكل صفحة */
export function loadPdfJs() {
    loading ??= Promise.all([
        import('pdfjs-dist/legacy/build/pdf.mjs'),
        import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url'),
    ]).then(([pdfjs, worker]) => {
        pdfjs.GlobalWorkerOptions.workerSrc = workerUrl(worker.default);
        return pdfjs;
    }).catch((e) => {
        loading = null;
        throw e;
    });
    return loading;
}

/**
 * يرسم الصفحة الأولى من القالب على كل canvas مُعطى (نسخة لكل صفحة طباعة).
 * canvases: مصفوفة أو كائن {فهرس: canvas}. scale: رقم (2 افتراضياً)، أو دالة تأخذ عرض الصفحة بمقياس 1 وتعيد المقياس.
 * يعيد true إن رُسم شيء.
 */
export async function renderTemplate(url, canvases, { scale = 2 } = {}) {
    const targets = (Array.isArray(canvases) ? canvases : Object.values(canvases || {})).filter(Boolean);
    if (!url || targets.length === 0) return false;

    const pdfjs = await loadPdfJs();
    const doc = await pdfjs.getDocument({ url, ...PDF_OPTIONS }).promise;
    try {
        const page = await doc.getPage(1);
        const factor = typeof scale === 'function' ? scale(page.getViewport({ scale: 1 }).width) : scale;
        const viewport = page.getViewport({ scale: factor });
        for (const canvas of targets) {
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            await page.render({ canvasContext: canvas.getContext('2d'), canvas, viewport }).promise;
        }
        return true;
    } finally {
        // كل مستند يشغّل عاملاً؛ إعادة الرسم (تغيير نوع المستند في المحرر) لا تترك عمّالاً معلّقة
        doc.destroy?.();
    }
}

/** للاختبارات: يعيد التحميل الكسول إلى البداية */
export function resetPdfJsForTests() {
    loading = null;
}
