// تسجيل رسالة الموظف الصوتية في المتصفح (E34.7): MediaRecorder بأول صيغة يقبلها المتصفح (ogg ثم webm ثم mp4)، وقناة واحدة
// مع إلغاء الصدى والضجيج، ومؤقت يوقف التسجيل عند الحد (دقيقتان)، ومعاينة من blob. الميكروفون يُطفأ (track.stop) لحظة
// انتهاء التسجيل وعند الحذف أو تغيير المحادثة، والـ blob يُحرَّر بـ revokeObjectURL. مفتاح منع التكرار (client_key) واحد لكل
// تسجيل: إعادة الإرسال بعد «المحوّل مشغول» بالمفتاح نفسه، والتسجيل الجديد بمفتاح جديد. الخادم يحوّل ويفحص كل شيء.
import { reactive } from 'vue';
import { uuid } from './useSendKey.js';

/** بالترتيب: Firefox يسجّل ogg، وChrome وEdge وAndroid webm، وSafari mp4 */
export const VOICE_TYPES = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/mp4'];

export function voiceSupported(env = globalThis) {
    return !!(env?.MediaRecorder && env?.navigator?.mediaDevices?.getUserMedia);
}

/** أول صيغة مدعومة، أو '' فيختار المتصفح صيغته */
export function pickMimeType(Recorder) {
    for (const type of VOICE_TYPES) {
        try {
            if (Recorder?.isTypeSupported?.(type)) return type;
        } catch {
            // متصفح يرمي بدل false
        }
    }
    return '';
}

export function extFor(mime) {
    const m = String(mime || '').toLowerCase();
    if (m.includes('ogg')) return 'ogg';
    if (m.includes('mp4') || m.includes('aac') || m.includes('m4a')) return 'm4a';
    return 'webm';
}

/** 0:42 */
export function clock(seconds) {
    const s = Math.max(0, Math.round(Number(seconds) || 0));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/** رسالة عربية لرفض الميكروفون أو تعذّره */
export function micError(e) {
    const name = e?.name || '';
    if (name === 'NotAllowedError' || name === 'SecurityError') return 'لم يُسمح باستعمال الميكروفون — اسمح به من إعدادات المتصفح ثم أعد المحاولة.';
    if (name === 'NotFoundError' || name === 'OverconstrainedError') return 'لا ميكروفون متاح على هذا الجهاز.';
    return 'تعذّر تشغيل الميكروفون — أعد المحاولة.';
}

/**
 * @param {{maxSeconds?: number, maxBytes?: number, env?: object, makeKey?: () => string}} options
 *   env: نافذة المتصفح (MediaRecorder وnavigator وURL وBlob وFile)، يُبدَّل في الاختبارات
 */
export function createVoiceRecorder({ maxSeconds = 120, maxBytes = 3_000_000, env = globalThis, makeKey = uuid } = {}) {
    // status: idle → starting → recording → preview → sending
    const s = reactive({ status: 'idle', elapsed: 0, seconds: 0, url: null, size: 0, mime: '', key: null, error: '' });
    let stream = null;
    let recorder = null;
    let chunks = [];
    let blob = null;
    let timer = null;
    let startedAt = 0;
    let generation = 0;

    function stopTracks() {
        for (const track of stream?.getTracks?.() || []) {
            try { track.stop(); } catch { /* لا شيء */ }
        }
        stream = null;
    }

    function clearTimer() {
        if (timer) clearInterval(timer);
        timer = null;
    }

    function revoke() {
        if (s.url) {
            try { env.URL?.revokeObjectURL?.(s.url); } catch { /* لا شيء */ }
        }
        s.url = null;
    }

    function reset() {
        clearTimer();
        stopTracks();
        revoke();
        recorder = null;
        chunks = [];
        blob = null;
        Object.assign(s, { status: 'idle', elapsed: 0, seconds: 0, size: 0, mime: '', key: null, error: '' });
    }

    function finalize(rec) {
        // أُلغي التسجيل أو بدأ غيره: onstop المتأخر لا يكتب شيئاً
        if (!rec || rec !== recorder) return;
        clearTimer();
        stopTracks();
        const type = rec.mimeType || s.mime || 'audio/webm';
        blob = new env.Blob(chunks, { type });
        chunks = [];
        s.mime = type;
        s.size = blob.size;
        s.seconds = Math.min(maxSeconds, s.elapsed);
        try { s.url = env.URL?.createObjectURL?.(blob) ?? null; } catch { s.url = null; }
        s.status = 'preview';
        s.error = blob.size > maxBytes ? 'التسجيل أكبر من 3MB — سجّل رسالة أقصر.' : '';
    }

    async function start() {
        if (s.status !== 'idle') return false;
        s.error = '';
        if (!voiceSupported(env)) {
            s.error = 'متصفحك لا يدعم تسجيل الصوت.';
            return false;
        }
        const mine = ++generation;
        s.status = 'starting';
        try {
            stream = await env.navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
        } catch (e) {
            s.status = 'idle';
            s.error = micError(e);
            return false;
        }
        // أُلغي أثناء طلب الإذن (تغيير المحادثة)
        if (mine !== generation || s.status !== 'starting') {
            stopTracks();
            return false;
        }
        const mime = pickMimeType(env.MediaRecorder);
        let rec;
        try {
            rec = new env.MediaRecorder(stream, mime ? { mimeType: mime, audioBitsPerSecond: 32000 } : { audioBitsPerSecond: 32000 });
        } catch {
            stopTracks();
            s.status = 'idle';
            s.error = 'تعذّر بدء التسجيل في هذا المتصفح.';
            return false;
        }
        recorder = rec;
        chunks = [];
        rec.ondataavailable = (e) => { if (rec === recorder && e?.data?.size) chunks.push(e.data); };
        rec.onstop = () => finalize(rec);
        s.mime = mime;
        s.key = makeKey();
        s.elapsed = 0;
        startedAt = Date.now();
        recorder.start(1000);
        s.status = 'recording';
        timer = setInterval(() => {
            s.elapsed = Math.min(maxSeconds, (Date.now() - startedAt) / 1000);
            if (s.elapsed >= maxSeconds) stop();
        }, 250);
        return true;
    }

    /** إيقاف التسجيل إلى المعاينة (يدوياً أو عند الحد) */
    function stop() {
        if (s.status !== 'recording') return;
        s.elapsed = Math.min(maxSeconds, (Date.now() - startedAt) / 1000);
        clearTimer();
        const rec = recorder;
        try { rec?.stop(); } catch { finalize(rec); }
    }

    /** «حذف» أو إلغاء أو تغيير المحادثة: الميكروفون يُطفأ والـ blob يُحرَّر ولا يبقى شيء */
    function discard() {
        generation++;
        s.error = '';
        const rec = recorder;
        recorder = null;
        if (rec && rec.state !== 'inactive') {
            try { rec.stop(); } catch { /* لا شيء */ }
        }
        reset();
    }

    /** الملف للإرسال باسم من صيغته (الخادم لا يستعمل الاسم) */
    function file() {
        if (!blob) return null;
        return new env.File([blob], `voice.${extFor(blob.type)}`, { type: blob.type });
    }

    return {
        state: s,
        start,
        stop,
        discard,
        file,
        tooLarge: () => !!blob && blob.size > maxBytes,
        /** بعد نجاح الإرسال: تسجيل جديد بمفتاح جديد */
        sent: () => reset(),
        setSending(on) {
            if (on && s.status === 'preview') s.status = 'sending';
            else if (!on && s.status === 'sending') s.status = 'preview';
        },
        setError(text) { s.error = text || ''; },
    };
}
