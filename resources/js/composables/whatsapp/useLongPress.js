// الضغط المطوّل على الجوال (E33): 500ms بلا تحريك يفتح قائمة إجراءات الرسالة، والتمرير أو الرفع المبكر يلغيه.
// الفأرة لها زر «⋯» يظهر بالمرور، فالضغط المطوّل للّمس والقلم وحدهما.

export const LONG_PRESS_MS = 500;
const MOVE_TOLERANCE = 10;

/**
 * @param {(e: PointerEvent) => void} onLongPress
 * @returns {{ handlers: Record<string, Function>, cancel: () => void }} handlers بأسماء الأحداث لـ v-on="handlers"
 */
export function useLongPress(onLongPress, { delay = LONG_PRESS_MS, timers = globalThis } = {}) {
    let timer = null;
    let start = null;
    let fired = false;

    const cancel = () => {
        if (timer !== null) timers.clearTimeout(timer);
        timer = null;
        start = null;
    };

    const handlers = {
        pointerdown(e) {
            if (e.pointerType === 'mouse' || (e.button !== undefined && e.button !== 0)) return;
            cancel();
            fired = false;
            start = { x: e.clientX ?? 0, y: e.clientY ?? 0 };
            timer = timers.setTimeout(() => {
                timer = null;
                fired = true;
                onLongPress(e);
            }, delay);
        },
        pointermove(e) {
            if (!start) return;
            if (Math.abs((e.clientX ?? 0) - start.x) > MOVE_TOLERANCE || Math.abs((e.clientY ?? 0) - start.y) > MOVE_TOLERANCE) cancel();
        },
        pointerup: cancel,
        pointercancel: cancel,
        pointerleave: cancel,
        // قائمة المتصفح (نسخ/تحديد) لا تظهر فوق قائمتنا بعد ضغط مطوّل نجح
        contextmenu(e) {
            if (fired) e.preventDefault();
        },
    };

    return { handlers, cancel };
}
