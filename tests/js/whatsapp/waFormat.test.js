import { describe, expect, it } from 'vitest';
import { parse, parseInline, toPlain } from '@/composables/whatsapp/useWaFormat.js';

const types = (tokens) => tokens.map((t) => t.type);

describe('inline formatting', () => {
    it('bold, italic, strike and nesting', () => {
        expect(parseInline('*عريض*')).toEqual([{ type: 'strong', children: [{ type: 'text', text: 'عريض' }] }]);
        expect(types(parseInline('نص _مائل_ و ~مشطوب~'))).toEqual(['text', 'em', 'text', 's']);
        // العلامة الملاصقة لحرف (و_مائل_) ليست تنسيقاً، كما في واتساب
        expect(types(parseInline('و_مائل_'))).toEqual(['text']);
        const nested = parseInline('*عريض _ومائل_*');
        expect(nested[0].type).toBe('strong');
        expect(types(nested[0].children)).toEqual(['text', 'em']);
    });

    it('markers inside words stay text', () => {
        expect(parseInline('2*3*4')).toEqual([{ type: 'text', text: '2*3*4' }]);
        expect(parseInline('snake_case_name')).toEqual([{ type: 'text', text: 'snake_case_name' }]);
        expect(parseInline('* ليس عريضاً *')).toEqual([{ type: 'text', text: '* ليس عريضاً *' }]);
    });

    it('code spans are literal', () => {
        expect(parseInline('`*x* https://a.b`')).toEqual([{ type: 'code', text: '*x* https://a.b' }]);
    });
});

describe('links', () => {
    it('http and https only, trailing punctuation trimmed', () => {
        const t = parseInline('زوروا https://nusk.example/offers?id=3.');
        expect(t[1]).toEqual({ type: 'link', href: 'https://nusk.example/offers?id=3', text: 'https://nusk.example/offers?id=3' });
        expect(t[2]).toEqual({ type: 'text', text: '.' });
    });

    it('never links javascript:, data: or schemeless www', () => {
        for (const s of ['javascript:alert(1)', 'data:text/html,<b>x</b>', 'www.example.com', 'JAVASCRIPT:alert(1)']) {
            expect(types(parseInline(s))).toEqual(['text']);
        }
    });

    it('markup stays text', () => {
        expect(parseInline('<img src=x onerror=alert(1)>')).toEqual([{ type: 'text', text: '<img src=x onerror=alert(1)>' }]);
    });
});

describe('blocks', () => {
    it('code block, quote and lists', () => {
        const b = parse('مرحبا\n```\nline *1*\n```\n> اقتباس\n- أ\n- ب\n1. واحد\n2. اثنان');
        expect(types(b)).toEqual(['p', 'pre', 'quote', 'ul', 'ol']);
        expect(b[1].text).toBe('line *1*');
        expect(b[3].items).toHaveLength(2);
        expect(b[4].items).toHaveLength(2);
    });

    it('an unterminated fence stays text', () => {
        expect(types(parse('``` بلا إغلاق'))).toEqual(['p']);
    });

    it('plain preview drops the markers', () => {
        expect(toPlain('*عريض* و _مائل_\n- بند')).toBe('عريض و مائل بند');
    });
});

describe('performance', () => {
    it('parses 4096 alternating stars quickly', () => {
        const s = '*a'.repeat(2048);
        const t0 = performance.now();
        parse(s);
        parse('*'.repeat(4096));
        parse('_a* '.repeat(1024));
        expect(performance.now() - t0).toBeLessThan(50);
    });
});
