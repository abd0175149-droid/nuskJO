import { describe, expect, it } from 'vitest';
import { compare, extract, hasSpelledNumbers, normalize, spelledWords } from '@/utils/numberGuard.js';

// الحالات نفسها في tests/Unit/WhatsApp/NumberGuardTest.php: الخادم والمتصفح يحكمان بالقواعد نفسها (E29)
const PAIRS = [
    ['أرقام هندية وتاريخ', '١٥٠ دينار في ٢٠/١١', '150 JOD on 20/11', true],
    ['رقم مقلوب', '150', '105', false],
    ['فاصل الآلاف', '1,500', '1500', true],
    ['الفاصلة العشرية العربية', '٢٫٥', '2.5', true],
    ['أرقام فارسية', '۱۵۰ تومان', '150 toman', true],
    ['الأصفار البادئة', '07/11', '7/11', true],
    ['رقم زائد', 'السعر 150', 'Price 150 for 2', false],
    ['التكرار يُعدّ', '150 و150', '150', false],
    ['المسافة الضيقة', '1 500', '1500', true],
    ['ليس فاصل آلاف', '11,20', '1120', false],
    ['رقم بالحروف', '150', 'one hundred fifty', false],
];

describe('numberGuard (تكافؤ مع NumberGuard في الخادم)', () => {
    it.each(PAIRS)('%s', (_name, source, translated, ok) => {
        expect(compare(source, translated).ok).toBe(ok);
    });

    it('normalizes and extracts like the server', () => {
        expect(normalize('١٬٥٠٠ و٢٫٥ و1,500,000')).toBe('1500 و2.5 و1500000');
        expect(extract('0 و0.5 و007 و150 في 20/11')).toEqual(['0', '0.5', '11', '150', '20', '7']);
    });

    it('finds spelled numbers by target language and skips articles and homographs', () => {
        expect(spelledWords('One hundred FIFTY', 'en')).toEqual(['one', 'hundred', 'fifty']);
        expect(hasSpelledNumbers('lima ratus ribu', 'id')).toBe(true);
        expect(hasSpelledNumbers('iki yüz', 'tr')).toBe(true);
        expect(hasSpelledNumbers('deux cents euros', 'fr')).toBe(true);
        expect(hasSpelledNumbers('تین سو روپے', 'ur')).toBe(true);
        expect(hasSpelledNumbers('والخمسة', 'ar')).toBe(true);
        expect(hasSpelledNumbers('Une chambre pour un client', 'fr')).toBe(false);
        expect(hasSpelledNumbers('Bir oda ayırdık', 'tr')).toBe(false);
        expect(hasSpelledNumbers('یک اتاق نه', 'fa')).toBe(false);
        expect(hasSpelledNumbers('Someone will often call', 'en')).toBe(false);
        expect(hasSpelledNumbers('on gün', 'tr')).toBe(true);
        expect(hasSpelledNumbers('travel on Friday', 'en')).toBe(false);
        expect(hasSpelledNumbers('travel on Friday')).toBe(false);
        expect(hasSpelledNumbers('ثلاثة أيام')).toBe(true);
    });

    it('reports missing, extra and spelled like the server', () => {
        expect(compare('السعر 150 في 20/11', 'Price 105 on 20/11', 'en')).toEqual({
            ok: false, missing: ['150'], extra: ['105'], spelled: false, spelled_words: [],
            source: ['11', '150', '20'], translated: ['105', '11', '20'],
        });
    });
});
