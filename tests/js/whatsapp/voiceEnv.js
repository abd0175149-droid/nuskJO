import { vi } from 'vitest';

// نافذة مزيّفة للمسجّل الصوتي (E34): MediaRecorder يكتب blob عند stop، وgetUserMedia يعيد مساراً له stop، وURL مراقَب
export function fakeEnv({ supported = ['audio/webm;codecs=opus', 'audio/mp4'], bytes = 2048, deny = null } = {}) {
    const track = { stop: vi.fn() };
    class FakeRecorder {
        static isTypeSupported(t) { return supported.includes(t); }

        constructor(stream, opts) {
            this.stream = stream;
            this.opts = opts;
            this.state = 'inactive';
            this.mimeType = opts.mimeType || 'audio/webm';
            env.recorders.push(this);
        }

        start(slice) { this.state = 'recording'; this.slice = slice; }

        stop() {
            this.state = 'inactive';
            this.ondataavailable?.({ data: new Blob([new Uint8Array(bytes)], { type: this.mimeType }) });
            this.onstop?.();
        }
    }
    const env = {
        recorders: [],
        track,
        MediaRecorder: FakeRecorder,
        navigator: { mediaDevices: { getUserMedia: vi.fn(async () => { if (deny) throw Object.assign(new Error('x'), { name: deny }); return { getTracks: () => [track] }; }) } },
        URL: { createObjectURL: vi.fn(() => 'blob:voice-1'), revokeObjectURL: vi.fn() },
        Blob,
        File,
    };
    return env;
}
