// تنسيق نص واتساب بأمان (E16.7): محلل يبني رموزاً يرسمها WaText بـ h() نصوصاً عادية، فلا HTML من نص العميل أبداً.
// *عريض* و_مائل_ و~مشطوب~ و`سطري` و```كتلة``` و«> » للاقتباس و«- » أو «* » أو «1. » للقوائم، وروابط http وhttps فقط.
// المسح خطي: مواضع الإغلاق الصالحة تُحسب مسبقاً لكل علامة، فلا تراجع كارثي في نص حتى 4096 حرفاً.

const MARKS = { '*': 'strong', _: 'em', '~': 's' };
const MAX_DEPTH = 3;
const WORD = /[\p{L}\p{N}]/u;
const URL_START = /https?:\/\//y;
const URL_BODY = /[^\s<>"']+/y;
const TRAILING = /[.,;:!?)\]}»،؛؟'"]+$/u;

const isWord = (ch) => ch !== undefined && WORD.test(ch);
const isSpace = (ch) => ch === undefined || /\s/u.test(ch);

/** علامة تفتح إن سبقها حدّ كلمة وتلاها ما ليس مسافة (2*3*4 يبقى نصاً) */
function canOpen(s, i) {
    return !isWord(s[i - 1]) && !isSpace(s[i + 1]);
}

/** وتغلق إن سبقها ما ليس مسافة وتلاها حدّ كلمة */
function canClose(s, j) {
    return !isSpace(s[j - 1]) && !isWord(s[j + 1]);
}

/** لكل موضع k: أقرب إغلاق صالح للعلامة m عند k أو بعده، أو -1 */
function closeTable(s, m) {
    const next = new Int32Array(s.length + 1).fill(-1);
    for (let k = s.length - 1; k >= 0; k--) {
        next[k] = s[k] === m && canClose(s, k) ? k : next[k + 1];
    }
    return next;
}

/** رابط http أو https عند i بعد قصّ علامات الترقيم في نهايته، ومُعاد التحقق منه بـ URL */
export function safeLink(s, i) {
    URL_START.lastIndex = i;
    if (!URL_START.test(s)) return null;
    URL_BODY.lastIndex = i;
    const m = URL_BODY.exec(s);
    if (!m) return null;
    const text = m[0].replace(TRAILING, '');
    try {
        const url = new URL(text);
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
        return { text, href: url.href };
    } catch {
        return null;
    }
}

/** رموز داخل السطر: text وcode وstrong وem وs وlink */
export function parseInline(s, depth = 0) {
    const out = [];
    let buf = '';
    const flush = () => {
        if (buf) out.push({ type: 'text', text: buf });
        buf = '';
    };
    const tables = {};
    const nextClose = (m, from) => {
        tables[m] ??= closeTable(s, m);
        return from <= s.length ? tables[m][from] : -1;
    };

    let i = 0;
    while (i < s.length) {
        const c = s[i];
        if (c === '`') {
            const j = s.indexOf('`', i + 1);
            if (j > i + 1) {
                flush();
                out.push({ type: 'code', text: s.slice(i + 1, j) });
                i = j + 1;
                continue;
            }
        }
        if (MARKS[c] && depth < MAX_DEPTH && canOpen(s, i)) {
            const j = nextClose(c, i + 2);
            if (j > i + 1) {
                flush();
                out.push({ type: MARKS[c], children: parseInline(s.slice(i + 1, j), depth + 1) });
                i = j + 1;
                continue;
            }
        }
        if (c === 'h') {
            const link = safeLink(s, i);
            if (link) {
                flush();
                out.push({ type: 'link', href: link.href, text: link.text });
                i += link.text.length;
                continue;
            }
        }
        buf += c;
        i++;
    }
    flush();
    return out;
}

/** أسطر نص عادي (بلا كتل كود) إلى كتل: p وquote وul وol */
function parseLines(text) {
    const blocks = [];
    const lines = text.split('\n');
    const kind = (line) => {
        if (/^> /u.test(line)) return 'quote';
        if (/^[-*] /u.test(line)) return 'ul';
        if (/^\d{1,3}\. /u.test(line)) return 'ol';
        return 'p';
    };
    for (const line of lines) {
        const k = kind(line);
        const last = blocks[blocks.length - 1];
        if (k === 'ul' || k === 'ol') {
            const item = parseInline(line.replace(/^([-*]|\d{1,3}\.) /u, ''));
            if (last?.type === k) last.items.push(item);
            else blocks.push({ type: k, items: [item] });
            continue;
        }
        const content = k === 'quote' ? line.slice(2) : line;
        if (last?.type === k) last.lines.push(parseInline(content));
        else blocks.push({ type: k, lines: [parseInline(content)] });
    }
    // سطر فارغ في آخر النص لا يصير فقرة
    return blocks.filter((b) => b.type !== 'p' || b.lines.some((l) => l.length));
}

/** النص كاملاً إلى كتل؛ ``` بلا إغلاق يبقى نصاً */
export function parse(text) {
    const s = String(text ?? '');
    const blocks = [];
    let i = 0;
    while (i < s.length) {
        const open = s.indexOf('```', i);
        const close = open === -1 ? -1 : s.indexOf('```', open + 3);
        if (open === -1 || close === -1) {
            blocks.push(...parseLines(s.slice(i)));
            break;
        }
        if (open > i) blocks.push(...parseLines(s.slice(i, open).replace(/\n$/u, '')));
        blocks.push({ type: 'pre', text: s.slice(open + 3, close).replace(/^\n|\n$/gu, '') });
        i = close + 3;
        if (s[i] === '\n') i++;
    }
    return blocks;
}

function inlineText(tokens) {
    return tokens.map((t) => (t.children ? inlineText(t.children) : t.text)).join('');
}

/** النص بلا علامات (معاينة القائمة): سطر واحد بلا روابط ولا كتل */
export function toPlain(text) {
    return parse(text)
        .map((b) => {
            if (b.type === 'pre') return b.text;
            if (b.items) return b.items.map(inlineText).join(' · ');
            return b.lines.map(inlineText).join(' ');
        })
        .join(' ')
        .replace(/\s+/gu, ' ')
        .trim();
}
