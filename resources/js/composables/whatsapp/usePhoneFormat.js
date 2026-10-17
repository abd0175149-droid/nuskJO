// رقم الهاتف مجزّأً للعرض فقط (E16): +962 79 123 4567. القيمة الخام لا تُعدَّل، والعرض داخل <bdi dir="ltr">

// رموز الدول الأكثر وروداً، الأطول أولاً عند المطابقة
const CODES = ['962', '966', '971', '965', '974', '973', '968', '970', '961', '963', '964', '967', '20', '44', '90', '1'];

function group(rest) {
    if (rest.length === 9) return [rest.slice(0, 2), rest.slice(2, 5), rest.slice(5)];
    if (rest.length === 10) return [rest.slice(0, 3), rest.slice(3, 6), rest.slice(6)];
    const out = [];
    let r = rest;
    while (r.length > 4) {
        out.push(r.slice(0, 3));
        r = r.slice(3);
    }
    if (r) out.push(r);
    return out;
}

export function formatPhone(raw) {
    const digits = String(raw ?? '').replace(/\D/g, '');
    if (!digits) return '';
    const code = CODES.filter((c) => digits.startsWith(c)).sort((a, b) => b.length - a.length)[0];
    if (!code || digits.length - code.length < 6) return '+' + digits;
    return ['+' + code, ...group(digits.slice(code.length))].join(' ');
}
