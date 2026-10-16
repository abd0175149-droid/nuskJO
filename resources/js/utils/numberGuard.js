// حارس الأرقام في المتصفح (E29): نسخة من App\Services\WhatsApp\NumberGuard للعرض الفوري في لوحة معاينة ترجمة الرد.
// الخادم هو الحَكَم عند الإرسال. القوائم بين علامتي words نسخة طبق الأصل من NumberGuard::WORDS (NumberGuardParityTest).

const DIGITS = {
    '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
};

/* words:start */
export const NUMBER_WORDS = {
    "en": ["one","two","three","four","five","six","seven","eight","nine","ten","eleven","twelve","thirteen","fourteen","fifteen","sixteen","seventeen","eighteen","nineteen","twenty","thirty","forty","fifty","sixty","seventy","eighty","ninety","hundred","thousand","million","billion"],
    "ar": ["واحد","واحدة","اثنان","اثنين","اثنتان","اثنتين","ثلاث","ثلاثة","أربع","أربعة","خمس","خمسة","ست","ستة","سبع","سبعة","ثمان","ثماني","ثمانية","تسع","تسعة","عشر","عشرة","عشرون","عشرين","ثلاثون","ثلاثين","أربعون","أربعين","خمسون","خمسين","ستون","ستين","سبعون","سبعين","ثمانون","ثمانين","تسعون","تسعين","مئة","مائة","مئتان","مئتين","مائتان","مائتين","ألف","الف","ألفان","ألفين","آلاف","مليون","ملايين"],
    "ur": ["تین","چار","پانچ","چھ","سات","آٹھ","نو","دس","گیارہ","بارہ","بیس","سو","ہزار","لاکھ","کروڑ"],
    "id": ["satu","dua","tiga","empat","lima","enam","tujuh","delapan","sembilan","sepuluh","sebelas","belas","puluh","seratus","ratus","seribu","ribu","juta"],
    "ms": ["satu","dua","tiga","empat","lima","enam","tujuh","lapan","sembilan","sepuluh","sebelas","belas","puluh","seratus","ratus","seribu","ribu","juta"],
    "tr": ["iki","üç","dört","beş","altı","yedi","sekiz","dokuz","on","yirmi","otuz","kırk","elli","altmış","yetmiş","seksen","doksan","yüz","bin","milyon"],
    "fr": ["deux","trois","quatre","cinq","six","sept","huit","neuf","dix","onze","douze","treize","quatorze","quinze","seize","vingt","trente","quarante","cinquante","soixante","cent","cents","mille","million","millions"],
    "es": ["dos","tres","cuatro","cinco","seis","siete","ocho","nueve","diez","once","doce","trece","catorce","quince","veinte","treinta","cuarenta","cincuenta","sesenta","setenta","ochenta","noventa","cien","ciento","cientos","mil","millón","millones"],
    "fa": ["دو","سه","چهار","پنج","شش","هفت","هشت","ده","بیست","سی","صد","هزار","میلیون"],
    "hi": ["तीन","चार","पांच","पाँच","छह","छः","सात","आठ","नौ","दस","बीस","सौ","हज़ार","हजार","लाख","करोड़"],
    "bn": ["দুই","তিন","চার","পাঁচ","ছয়","সাত","আট","দশ","বিশ","একশ","শত","হাজার","লাখ","কোটি"]
};
/* words:end */

// بلا لغة هدف: الإنجليزية والعربية وحدهما («on» التركية عشرة)
const DEFAULT_LANGS = ['en', 'ar'];

/** الأرقام الهندية والفارسية أرقاماً لاتينية */
export function latinDigits(text) {
    return String(text ?? '').replace(/[٠-٩۰-۹]/g, (d) => DIGITS[d]);
}

/** أرقام لاتينية، والفاصلة العشرية العربية نقطة، وفواصل الآلاف (٬ , والمسافة الضيقة والرفيعة) قبل ثلاث خانات تُحذف */
export function normalize(text) {
    return latinDigits(text).replace(/٫/g, '.').replace(/(?<=\d)[٬,  ](?=\d{3}(?!\d))/gu, '');
}

const stripZeros = (n) => {
    const [int, frac] = n.split('.');
    const i = int.replace(/^0+/, '') || '0';
    return frac === undefined ? i : `${i}.${frac}`;
};

/** الأرقام مجموعةً متعددة مرتبة، بلا أصفار بادئة (07 = 7، و0 يبقى 0) */
export function extract(text) {
    return (normalize(text).match(/\d+(?:\.\d+)?/g) || []).map(stripZeros).sort();
}

const escape = (w) => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** كلمات أرقام مكتوبة بالحروف، بلغة الهدف وحدها إن عُرفت، وإلا الإنجليزية والعربية */
export function spelledWords(text, lang = null) {
    const langs = lang && NUMBER_WORDS[lang] ? [lang] : DEFAULT_LANGS;
    const found = new Set();
    for (const code of langs) {
        // العربية: حرف عطف أو جر و«ال» قبل الكلمة (والخمسة، بخمسين)
        const prefix = code === 'ar' ? '(?:[وفبلك]?(?:ال)?)' : '';
        const re = new RegExp(`(?<![\\p{L}\\p{M}\\p{N}])${prefix}(${NUMBER_WORDS[code].map(escape).join('|')})(?![\\p{L}\\p{M}\\p{N}])`, 'giu');
        for (const m of String(text ?? '').matchAll(re)) found.add(m[1].toLowerCase());
    }
    return [...found];
}

export const hasSpelledNumbers = (text, lang = null) => spelledWords(text, lang).length > 0;

/** ما في a وليس في b بتعدده */
function minus(a, b) {
    const left = new Map();
    b.forEach((n) => left.set(n, (left.get(n) || 0) + 1));
    return a.filter((n) => {
        if ((left.get(n) || 0) > 0) {
            left.set(n, left.get(n) - 1);
            return false;
        }
        return true;
    });
}

/** ok: المجموعتان متساويتان ولا رقم بالحروف في الترجمة. الحقول كما يعيدها الخادم */
export function compare(source, translated, lang = null) {
    const a = extract(source);
    const b = extract(translated);
    const missing = minus(a, b);
    const extra = minus(b, a);
    const words = spelledWords(translated, lang);
    return { ok: !missing.length && !extra.length && !words.length, missing, extra, spelled: words.length > 0, spelled_words: words, source: a, translated: b };
}
