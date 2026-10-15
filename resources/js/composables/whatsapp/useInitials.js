// صورة رمزية من الأحرف الأولى بلون ثابت لكل محادثة (E16). Cloud API لا يعطي صورة العميل

const MARKS = /[ً-ٰٟـ​-‏‪-‮⁦-⁩]/gu;

/** أول حرف من أول كلمتين (عربي) أو أول حرفين لكلمة لاتينية واحدة؛ بلا حروف (رقم أو رموز) يعطي «#» */
export function initials(name) {
    const words = String(name ?? '')
        .replace(MARKS, '')
        .split(/\s+/u)
        .map((w) => w.replace(/[^\p{L}\p{N}]/gu, ''))
        .filter((w) => w && w !== 'ال' && /\p{L}/u.test(w));
    if (!words.length) return '#';
    const letters = words.map((w) => [...w].find((ch) => /\p{L}/u.test(ch)));
    if (words.length === 1) {
        const w = [...words[0]].filter((ch) => /\p{L}/u.test(ch));
        return /[A-Za-z]/.test(w[0]) ? w.slice(0, 2).join('').toUpperCase() : w[0];
    }
    return letters.slice(0, 2).join('').toUpperCase();
}

/** ثمانية ألوان بتباين AA مع الأبيض في السمتين، والفئات مكتوبة كاملة ليلتقطها Tailwind */
export const AVATAR_COLORS = [
    'bg-sky-700', 'bg-emerald-700', 'bg-amber-700', 'bg-rose-700',
    'bg-violet-700', 'bg-teal-700', 'bg-indigo-700', 'bg-fuchsia-700',
];

/** djb2: المفتاح نفسه (معرّف المحادثة أو الموظف) يعطي اللون نفسه دائماً */
export function hashKey(key) {
    let h = 5381;
    for (const ch of String(key ?? '')) h = ((h * 33) ^ ch.codePointAt(0)) >>> 0;
    return h;
}

export function colorFor(key) {
    return AVATAR_COLORS[hashKey(key) % AVATAR_COLORS.length];
}
