import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { clock, createVoiceRecorder, extFor, micError, pickMimeType, VOICE_TYPES, voiceSupported } from '@/composables/whatsapp/useVoiceRecorder.js';
import { fakeEnv } from './voiceEnv.js';

// المسجّل (E34.7) بـ MediaRecorder وgetUserMedia مزيّفين: الصيغة بالترتيب، والإيقاف عند الحد، والميكروفون يُطفأ والـ blob
// يُحرَّر، ومفتاح ثابت لكل تسجيل.
describe('createVoiceRecorder', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('asks for one channel with echo and noise suppression, in the first format the browser accepts', async () => {
        const env = fakeEnv();
        const r = createVoiceRecorder({ env, makeKey: () => 'k1' });
        expect(await r.start()).toBe(true);

        expect(env.navigator.mediaDevices.getUserMedia).toHaveBeenCalledWith({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
        expect(env.recorders[0].opts).toEqual({ mimeType: 'audio/webm;codecs=opus', audioBitsPerSecond: 32000 });
        expect(r.state.status).toBe('recording');
        expect(VOICE_TYPES).toEqual(['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/mp4']);
        expect(pickMimeType({ isTypeSupported: (t) => t !== 'audio/webm;codecs=opus' })).toBe('audio/ogg;codecs=opus');
        expect(pickMimeType({ isTypeSupported: (t) => t === 'audio/mp4' })).toBe('audio/mp4');
        expect(pickMimeType({ isTypeSupported: () => { throw new Error('x'); } })).toBe('');
    });

    it('stops by itself at two minutes and turns the microphone off', async () => {
        const env = fakeEnv();
        const r = createVoiceRecorder({ env, maxSeconds: 120 });
        await r.start();

        vi.advanceTimersByTime(60_000);
        expect(r.state.status).toBe('recording');
        expect(Math.round(r.state.elapsed)).toBe(60);
        vi.advanceTimersByTime(60_250);

        expect(r.state.status).toBe('preview');
        expect(r.state.seconds).toBe(120);
        expect(r.state.url).toBe('blob:voice-1');
        expect(env.track.stop).toHaveBeenCalled();
        const file = r.file();
        expect([file.name, file.type, file.size]).toEqual(['voice.webm', 'audio/webm;codecs=opus', 2048]);
    });

    it('delete stops the tracks and revokes the blob, and a late stop event writes nothing', async () => {
        const env = fakeEnv();
        const r = createVoiceRecorder({ env });
        await r.start();
        vi.advanceTimersByTime(5000);
        r.stop();
        expect(r.state.status).toBe('preview');

        r.discard();
        expect(env.URL.revokeObjectURL).toHaveBeenCalledWith('blob:voice-1');
        expect(r.state).toMatchObject({ status: 'idle', url: null, key: null });
        expect(r.file()).toBeNull();

        // إلغاء أثناء التسجيل: الميكروفون يُطفأ فوراً، وحدث stop المتأخر لا يعيد المعاينة
        await r.start();
        env.track.stop.mockClear();
        const rec = env.recorders[1];
        r.discard();
        expect(env.track.stop).toHaveBeenCalled();
        rec.onstop?.();
        expect(r.state.status).toBe('idle');
    });

    it('keeps one key per recording and a new one for the next', async () => {
        let n = 0;
        const r = createVoiceRecorder({ env: fakeEnv(), makeKey: () => `key-${++n}` });
        await r.start();
        r.stop();
        const first = r.state.key;
        r.setSending(true);
        r.setSending(false);
        r.setError('المحوّل مشغول');
        expect(r.state.key).toBe(first);

        r.sent();
        await r.start();
        expect(r.state.key).toBe('key-2');
        expect(first).toBe('key-1');
    });

    it('refuses a recording over 3MB with an Arabic reason', async () => {
        const r = createVoiceRecorder({ env: fakeEnv({ bytes: 3_000_001 }) });
        await r.start();
        r.stop();
        expect(r.tooLarge()).toBe(true);
        expect(r.state.error).toContain('3MB');
    });

    it('explains a refused microphone and stays idle', async () => {
        const r = createVoiceRecorder({ env: fakeEnv({ deny: 'NotAllowedError' }) });
        expect(await r.start()).toBe(false);
        expect(r.state.status).toBe('idle');
        expect(r.state.error).toContain('لم يُسمح باستعمال الميكروفون');
        expect(micError({ name: 'NotFoundError' })).toBe('لا ميكروفون متاح على هذا الجهاز.');
    });

    it('a conversation change during the permission prompt leaves nothing open', async () => {
        const env = fakeEnv();
        const r = createVoiceRecorder({ env });
        const pending = r.start();
        r.discard();
        expect(await pending).toBe(false);
        expect(env.track.stop).toHaveBeenCalled();
        expect(env.recorders).toHaveLength(0);
        expect(r.state.status).toBe('idle');
    });
});

describe('voice helpers', () => {
    it('detects support, names files by format and formats durations', () => {
        expect(voiceSupported(fakeEnv())).toBe(true);
        expect(voiceSupported({ navigator: { mediaDevices: { getUserMedia() {} } } })).toBe(false);
        expect(voiceSupported({ MediaRecorder: class {}, navigator: {} })).toBe(false);
        expect([extFor('audio/ogg;codecs=opus'), extFor('audio/webm;codecs=opus'), extFor('audio/mp4'), extFor('')]).toEqual(['ogg', 'webm', 'm4a', 'webm']);
        expect([clock(0), clock(42.4), clock(120), clock(61)]).toEqual(['0:00', '0:42', '2:00', '1:01']);
    });
});
