import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

// نص العميل لا يُرسم HTML أبداً في الواتساب (E16.7): لا v-html ولا innerHTML في مكوّناته وصفحاته
function files(dir) {
    return readdirSync(dir).flatMap((f) => {
        const p = join(dir, f);
        return statSync(p).isDirectory() ? files(p) : [p];
    });
}

describe('no raw HTML in WhatsApp views', () => {
    const roots = ['resources/js/Components/WhatsApp', 'resources/js/Pages/WhatsApp'];
    it.each(roots)('%s', (root) => {
        const offenders = files(root).filter((f) => /\bv-html\s*=|innerHTML\s*[=:]/.test(readFileSync(f, 'utf8')));
        expect(offenders).toEqual([]);
    });
});
