// التكاملات الصادرة وخادم MCP (E40): دوال صافية للصفحة ومكوّناتها، بلا نداءات شبكة.

export const STATUS_LABELS = { pending: 'معلّقة', delivering: 'قيد التسليم', delivered: 'مُسلَّمة', dead: 'فاشلة' };

export const STATUS_CLASSES = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    delivering: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    delivered: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
    dead: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
};

export const statusLabel = (status) => STATUS_LABELS[status] || status;
export const statusClass = (status) => STATUS_CLASSES[status] || 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';

const GUARD = {
    private_address: 'العنوان صار يحل إلى شبكة خاصة',
    dns_failed: 'تعذّر تحليل اسم المضيف',
    not_https: 'الرابط ليس https',
    port: 'المنفذ غير مسموح',
};

/** نص عربي قصير لآخر خطأ تسليم (الرمز الخام يبقى في title) */
export function errorText(error) {
    if (!error) return '';
    const s = String(error);
    let m = s.match(/^http_(\d{3})$/);
    if (m) return `ردّ المستقبل ${m[1]}`;
    m = s.match(/^redirect_(\d{3})$/);
    if (m) return `تحويل ${m[1]} (لا يُتبع)`;
    m = s.match(/^ssrf_rejected:(\w+)$/);
    if (m) return `رُفض الرابط عند الإرسال: ${GUARD[m[1]] || m[1]}`;
    if (s.startsWith('connection_failed')) return 'تعذّر الاتصال بالمستقبل (مهلة أو شبكة)';
    if (s === 'endpoint_missing') return 'الوجهة حُذفت';
    if (s === 'secret_unreadable') return 'تعذّر قراءة السر';
    return s;
}

/** الحقول التي ستخرج حرفياً لحدث، بحسب خيار المبلغ */
export function fieldsFor(entry, includeAmounts) {
    if (!entry) return [];
    return includeAmounts ? entry.fields : entry.fields.filter((f) => !(entry.amount_fields || []).includes(f));
}

/** عقدة Code في n8n: تحقق Standard Webhooks (طابع 5 دقائق، وأي توقيع صالح أثناء التدوير) */
export function verifySnippet() {
    return [
        "const crypto = require('crypto');",
        "const secret = $env.NUSK_WEBHOOK_SECRET; // whsec_...",
        "const h = $input.first().json.headers;",
        "const id = h['webhook-id'], ts = h['webhook-timestamp'];",
        "// عقدة Webhook بخيار Raw Body: البايتات كما وصلت (إعادة ترميز JSON تكسر التوقيع)",
        "const body = Buffer.from($input.first().binary.data.data, 'base64').toString('utf8');",
        "if (Math.abs(Date.now() / 1000 - Number(ts)) > 300) throw new Error('old timestamp');",
        "const key = Buffer.from(secret.slice(6), 'base64');",
        "const expected = 'v1,' + crypto.createHmac('sha256', key).update(`${id}.${ts}.${body}`).digest('base64');",
        "if (!h['webhook-signature'].split(' ').includes(expected)) throw new Error('bad signature');",
        'return $input.all();',
    ].join('\n');
}

/** ملف .mcp.json محلي لـ Claude Code (لا يُتتبع في git) */
export function mcpConfigSnippet(url, token = '<الرمز>') {
    return JSON.stringify({
        mcpServers: {
            'nusk-reports': {
                type: 'http',
                url,
                headers: {
                    Authorization: `Bearer ${token}`,
                    'CF-Access-Client-Id': '<من Cloudflare>',
                    'CF-Access-Client-Secret': '<من Cloudflare>',
                },
            },
        },
    }, null, 2);
}

export const when = (value) => (value ? new Date(value).toLocaleString('en-GB', { dateStyle: 'short', timeStyle: 'short' }) : '—');

/** أول رسالة خطأ من رد axios (422 أو 409 أو message) */
export function responseError(e, fallback = 'تعذّر الحفظ') {
    const data = e?.response?.data;
    if (data?.errors) return Object.values(data.errors).flat()[0] || fallback;
    return data?.message || fallback;
}
