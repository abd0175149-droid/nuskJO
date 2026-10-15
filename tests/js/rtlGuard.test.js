import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

// حارس RTL بسقف متدرج لصفحات الواتساب ومكوّناته (A19، جزء C295 في E16): الواجهة RTL ثابتة، فالفئات الفيزيائية
// (ml/mr/pl/pr وleft/right وtext-left/right وrounded-l/r وborder-l/r) تنقلب خطأً؛ البديل المنطقي ms/me/ps/pe وstart/end
// وtext-start/end وrounded-s/e وborder-s/e. ملف جديد بلا أي منها، والقائم لا يزيد عن سقفه؛ وإن نقص فاخفض السقف.
const ROOT = process.cwd();
const DIRS = ['resources/js/Pages/WhatsApp', 'resources/js/Components/WhatsApp'];
const FILES = ['resources/js/Components/AppModal.vue', 'resources/js/Components/ConfirmDialog.vue'];

const CEILING = {
    'resources/js/Pages/WhatsApp/AdminNumbers.vue': 6,
    'resources/js/Pages/WhatsApp/Analytics.vue': 3,
    'resources/js/Pages/WhatsApp/ErasureRequests.vue': 1,
    // نسخة التراجع، تُحذف بعد أسبوعين من تفعيل inbox_v2 (E16.11)
    'resources/js/Pages/WhatsApp/InboxLegacy.vue': 10,
    'resources/js/Pages/WhatsApp/Routing.vue': 3,
    'resources/js/Pages/WhatsApp/Settings.vue': 4,
    'resources/js/Components/WhatsApp/BreakerPanel.vue': 1,
    'resources/js/Components/WhatsApp/LinkClientDialog.vue': 1,
    'resources/js/Components/WhatsApp/OneTimeSecretModal.vue': 1,
};

const CLASS = /(?<=^|[\s"'`:])-?(?:(?:m|p|scroll-m|scroll-p)[lr]-[\w.[\]/-]+|(?:left|right)-[\w.[\]/-]+|text-(?:left|right)\b|float-(?:left|right)\b|clear-(?:left|right)\b|rounded-(?:l|r|tl|tr|bl|br)(?:-[\w[\]/.-]+)?\b|border-[lr](?:-[\w[\]/.-]+)?\b)/g;
const CSS = /(?:margin|padding|border)-(?:left|right)\s*:|text-align\s*:\s*(?:left|right)|\b(?:left|right)\s*:\s*[\d.-]/g;

const walk = (dir) => readdirSync(dir).flatMap((f) => {
    const p = join(dir, f);
    if (statSync(p).isDirectory()) return walk(p);
    return /\.(vue|js)$/.test(p) ? [p] : [];
});

export function physicalSides(source) {
    return [...source.matchAll(CLASS), ...source.matchAll(CSS)].map((m) => m[0]);
}

describe('RTL guard', () => {
    const files = [...DIRS.flatMap((d) => walk(join(ROOT, d))), ...FILES.map((f) => join(ROOT, f))]
        .map((p) => relative(ROOT, p).split('\\').join('/'));

    it('recognises physical sides and ignores logical ones', () => {
        expect(physicalSides('class="ml-2 pr-4 text-right rounded-bl-sm border-l-2 left-0 sm:mr-1"')).toHaveLength(7);
        expect(physicalSides('class="ms-2 pe-4 text-start rounded-es-sm border-s-2 start-0 inset-x-0 me-auto"')).toEqual([]);
        expect(physicalSides('style="margin-left: 4px; text-align: right"')).toHaveLength(2);
    });

    it.each(files)('%s stays within its ceiling', (file) => {
        const found = physicalSides(readFileSync(join(ROOT, file), 'utf8'));
        const ceiling = CEILING[file] ?? 0;
        expect(found.length, `فئات فيزيائية جديدة في ${file}: ${found.join(' ')} — استعمل البدائل المنطقية`).toBeLessThanOrEqual(ceiling);
        expect(found.length, `${file} نزل إلى ${found.length}: اخفض سقفه في rtlGuard.test.js`).toBe(ceiling);
    });
});
