// Enter يرسل على سطح المكتب فقط (فأرة ولوحة مفاتيح)، وعلى اللمس سطر جديد والإرسال بالزر (E16)

export function isFinePointer(matchMediaFn = globalThis.matchMedia) {
    try {
        return !!matchMediaFn && matchMediaFn('(hover: hover) and (pointer: fine)').matches;
    } catch {
        return false;
    }
}

/** Enter بلا Shift، خارج تركيب الحروف (IME)، وعلى مؤشر دقيق */
export function sendOnEnter(event, fine = isFinePointer()) {
    return event.key === 'Enter' && !event.shiftKey && !event.isComposing && fine;
}
