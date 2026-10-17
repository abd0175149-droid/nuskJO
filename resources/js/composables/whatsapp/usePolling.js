// استطلاع دوري (E16): لا يتداخل طلبان، ويتوقف والصفحة مخفية ويستأنف بطلب فوري عند عودتها، ويتباطأ عند الفشل المتكرر.
// مع البث اللحظي (E28) يصير احتياطاً: setInterval(60000) والمقبس متصل، و15000 حين ينقطع (useInboxRealtime).

export function createPoller(tick, { interval: initial = 15000, maxInterval = 60000, doc = globalThis.document, onError } = {}) {
    let interval = initial;
    let timer = null;
    let inFlight = null;
    let failures = 0;
    let running = false;

    const hidden = () => !!doc && doc.visibilityState === 'hidden';
    const delay = () => Math.min(maxInterval, interval * 2 ** Math.min(failures, 3));

    const schedule = () => {
        clearTimeout(timer);
        if (running) timer = setTimeout(cycle, delay());
    };

    async function run() {
        if (inFlight) return inFlight;
        inFlight = (async () => {
            try {
                await tick();
                failures = 0;
            } catch (e) {
                failures++;
                onError?.(e, failures);
            } finally {
                inFlight = null;
            }
        })();
        return inFlight;
    }

    async function cycle() {
        if (!hidden()) await run();
        schedule();
    }

    const onVisibility = () => {
        if (running && !hidden()) {
            run();
            schedule();
        }
    };

    return {
        start() {
            if (running) return;
            running = true;
            doc?.addEventListener?.('visibilitychange', onVisibility);
            schedule();
        },
        stop() {
            running = false;
            clearTimeout(timer);
            doc?.removeEventListener?.('visibilitychange', onVisibility);
        },
        run,
        failures: () => failures,
        nextDelay: delay,
        /** فترة الاستطلاع الأساسية (E28)؛ تُطبَّق على الموعد التالي فوراً */
        setInterval(ms) {
            if (!(ms > 0) || ms === interval) return;
            interval = ms;
            if (running) schedule();
        },
        interval: () => interval,
    };
}
