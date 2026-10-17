// مفتاح منع التكرار لرسالة الموظف (E19، ترويسة Idempotency-Key): مفتاح لكل مسودة، ثابت عند إعادة المحاولة للنص نفسه،
// ويتجدد عند تغيّر النص أو بعد النجاح. فنقرتان أو إعادة بعد انقطاع لا تُخرجان رسالتين للعميل.

/** UUID v4: crypto.randomUUID إن وُجد (سياق آمن)، وإلا getRandomValues */
export function uuid() {
    const c = globalThis.crypto;
    if (c?.randomUUID) return c.randomUUID();
    const b = new Uint8Array(16);
    if (c?.getRandomValues) c.getRandomValues(b);
    else for (let i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export function createSendKeys(make = uuid) {
    const keys = new Map();
    return {
        /** المفتاح لهذه المحادثة وهذا النص: نفسه ما دام النص نفسه */
        keyFor(id, text) {
            const body = String(text ?? '').trim();
            const entry = keys.get(id);
            if (entry && entry.text === body) return entry.key;
            const key = make();
            keys.set(id, { key, text: body });
            return key;
        },
        /** بعد النجاح (أو replayed): المسودة التالية بمفتاح جديد */
        done(id) {
            keys.delete(id);
        },
        /** المفتاح مستعمل لنص آخر (IDEMPOTENCY_MISMATCH): يُستبدل */
        rotate(id) {
            keys.delete(id);
        },
    };
}
