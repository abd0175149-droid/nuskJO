import { describe, expect, it } from 'vitest';
import { errorText, fieldsFor, mcpConfigSnippet, responseError, statusClass, statusLabel, verifySnippet } from '@/composables/useIntegrations.js';

// دوال صفحة التكاملات (E40): نصوص الأخطاء، والحقول التي ستخرج، ومقاطع التحقق والإعداد
describe('useIntegrations', () => {
    it('turns delivery error codes into short Arabic text', () => {
        expect(errorText('http_503')).toBe('ردّ المستقبل 503');
        expect(errorText('redirect_302')).toContain('لا يُتبع');
        expect(errorText('ssrf_rejected:private_address')).toContain('شبكة خاصة');
        expect(errorText('connection_failed: ConnectionException timeout [hooks.example.com]')).toBe('تعذّر الاتصال بالمستقبل (مهلة أو شبكة)');
        expect(errorText(null)).toBe('');
        expect(errorText('something_else')).toBe('something_else');
    });

    it('drops the amount fields unless the endpoint includes amounts', () => {
        const entry = { key: 'invoice.approved', fields: ['invoice_number', 'approved_at', 'total_sell_jod'], amount_fields: ['total_sell_jod'] };
        expect(fieldsFor(entry, false)).toEqual(['invoice_number', 'approved_at']);
        expect(fieldsFor(entry, true)).toEqual(['invoice_number', 'approved_at', 'total_sell_jod']);
        expect(fieldsFor(null, true)).toEqual([]);
    });

    it('labels statuses', () => {
        expect(statusLabel('dead')).toBe('فاشلة');
        expect(statusClass('delivered')).toContain('green');
        expect(statusLabel('weird')).toBe('weird');
    });

    it('gives a Standard Webhooks verification snippet that uses the raw body and a five minute window', () => {
        const s = verifySnippet();
        expect(s).toContain("createHmac('sha256'");
        expect(s).toContain('${id}.${ts}.${body}');
        expect(s).toContain('> 300');
        expect(s).toContain("split(' ')");
        expect(s).toContain('Raw Body');
        expect(s).not.toContain('whsec_' + 'A');
    });

    it('builds a local .mcp.json with the bearer token and the Cloudflare headers', () => {
        const json = JSON.parse(mcpConfigSnippet('https://nusk.example.org/mcp/owner', '5|njmcp_abc'));
        const server = json.mcpServers['nusk-reports'];
        expect(server.url).toBe('https://nusk.example.org/mcp/owner');
        expect(server.headers.Authorization).toBe('Bearer 5|njmcp_abc');
        expect(Object.keys(server.headers)).toEqual(['Authorization', 'CF-Access-Client-Id', 'CF-Access-Client-Secret']);
    });

    it('reads the first validation error or the message', () => {
        expect(responseError({ response: { data: { errors: { url: ['العنوان يحل إلى شبكة خاصة'] } } } })).toBe('العنوان يحل إلى شبكة خاصة');
        expect(responseError({ response: { data: { message: 'لا' } } })).toBe('لا');
        expect(responseError({}, 'بديل')).toBe('بديل');
    });
});
