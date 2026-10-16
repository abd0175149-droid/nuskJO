import { describe, expect, it, vi } from 'vitest';
import { extOf, formatMb, needsConversion, prepareAttachment, specFor } from '@/composables/whatsapp/useAttachment.js';
import { imageToJpeg, jpgName } from '@/utils/imageToJpeg.js';

// فحص المرفق في المتصفح قبل الرفع (E32.10): النوع والحد من سياسة الخادم، وHEIC/WebP إلى JPEG، وتصغير الصورة الكبيرة مرة
const MB = 1024 * 1024;
const policy = {
    enabled: true,
    types: [
        { mime: 'image/jpeg', kind: 'image', max: 5 * MB, exts: ['jpg', 'jpeg'] },
        { mime: 'image/png', kind: 'image', max: 5 * MB, exts: ['png'] },
        { mime: 'video/mp4', kind: 'video', max: 16 * MB, exts: ['mp4'] },
        { mime: 'application/pdf', kind: 'document', max: 20 * MB, exts: ['pdf'] },
    ],
};
// يكفي الاسم والنوع والحجم: الفحص لا يقرأ المحتوى (الخادم يفحصه بـ finfo)
const file = (name, type, size = 1000) => ({ name, type, size });
const jpeg = (size) => file('IMG_1.jpg', 'image/jpeg', size);

describe('prepareAttachment', () => {
    it('accepts a PDF within its limit, by type or by extension', async () => {
        expect(await prepareAttachment(file('visa.pdf', 'application/pdf', 2 * MB), policy))
            .toMatchObject({ ok: true, kind: 'document', mime: 'application/pdf', converted: false });
        expect(await prepareAttachment(file('scan.PDF', ''), policy)).toMatchObject({ ok: true, kind: 'document' });
    });

    it('refuses other types with the allowed list, and an empty file', async () => {
        const res = await prepareAttachment(file('setup.exe', 'application/x-msdownload'), policy);
        expect(res.ok).toBe(false);
        expect(res.error).toContain('نوع الملف غير مسموح');
        expect(res.error).toContain('صورة JPG أو PNG');
        expect(res.error).toContain('PDF');
        expect(res.error).toContain('فيديو MP4');
        expect((await prepareAttachment(file('a.pdf', 'application/pdf', 0), policy)).error).toBe('الملف فارغ.');
        expect((await prepareAttachment(null, policy)).ok).toBe(false);
    });

    it('refuses a large PDF and a long video, each with its own message', async () => {
        expect((await prepareAttachment(file('big.pdf', 'application/pdf', 21 * MB), policy)).error).toContain('20MB');
        expect((await prepareAttachment(file('clip.mp4', 'video/mp4', 17 * MB), policy)).error).toBe('الفيديو أكبر من 16MB — قصّه أو أرسله رابطاً.');
    });

    it('converts HEIC to JPEG before the checks', async () => {
        const convert = vi.fn(async () => jpeg(MB));
        const res = await prepareAttachment(file('IMG_1.HEIC', ''), policy, convert);
        expect(res).toMatchObject({ ok: true, kind: 'image', mime: 'image/jpeg', converted: true });
        expect(convert).toHaveBeenCalledTimes(1);
    });

    it('explains when the browser cannot decode the photo', async () => {
        const res = await prepareAttachment(file('IMG_1.heic', 'image/heic'), policy, async () => { throw new Error('decode'); });
        expect(res.ok).toBe(false);
        expect(res.error).toContain('تعذّر تحويل الصورة');
    });

    it('downscales a 6MB photo once, and refuses it if still too large', async () => {
        const small = vi.fn(async () => jpeg(2 * MB));
        expect(await prepareAttachment(file('wide.png', 'image/png', 6 * MB), policy, small))
            .toMatchObject({ ok: true, mime: 'image/jpeg', converted: true });
        expect(small).toHaveBeenCalledTimes(1);

        const still = vi.fn(async () => jpeg(6 * MB));
        const res = await prepareAttachment(file('wide.png', 'image/png', 6 * MB), policy, still);
        expect(res.error).toBe('الصورة أكبر من 5MB — صغّرها أو حوّلها إلى PDF.');
        expect(still).toHaveBeenCalledTimes(1);
    });

    it('does not shrink a converted HEIC twice', async () => {
        const convert = vi.fn(async () => jpeg(6 * MB));
        expect((await prepareAttachment(file('IMG_2.heic', 'image/heic'), policy, convert)).ok).toBe(false);
        expect(convert).toHaveBeenCalledTimes(1);
    });
});

describe('attachment helpers', () => {
    it('reads extensions, sizes and types', () => {
        expect(extOf('a.b.PDF')).toBe('pdf');
        expect(extOf('noext')).toBe('');
        expect(formatMb(16 * MB)).toBe('16MB');
        expect(specFor(file('x.jpeg', ''), policy).mime).toBe('image/jpeg');
        expect(specFor(file('x.docx', ''), policy)).toBeNull();
        expect(needsConversion(file('x.webp', ''))).toBe(true);
        expect(needsConversion(file('x', 'image/heif'))).toBe(true);
        expect(needsConversion(file('x.jpg', 'image/jpeg'))).toBe(false);
    });

    it('names the converted photo .jpg', () => {
        expect(jpgName('IMG_0001.HEIC')).toBe('IMG_0001.jpg');
        expect(jpgName('a.b.webp')).toBe('a.b.jpg');
        expect(jpgName('')).toBe('صورة.jpg');
    });

    it('fails clearly where the browser cannot decode images', async () => {
        const original = globalThis.createImageBitmap;
        delete globalThis.createImageBitmap;
        try {
            await expect(imageToJpeg(file('x.heic', 'image/heic'))).rejects.toThrow();
        } finally {
            if (original) globalThis.createImageBitmap = original;
        }
    });
});
