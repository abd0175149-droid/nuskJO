import { describe, expect, it, vi } from 'vitest';
import { createOutbox } from '@/composables/whatsapp/useOutbox.js';
import { applyUpdates } from '@/composables/whatsapp/useThread.js';

// الرسالة الصوتية على فقاعات E32 (E34): voice=1 في الطلب نفسه، ومفتاح التسجيل لا مفتاح جديد، ورفض «المحوّل مشغول» يعود
// للمسجّل برمزه. والتفريغ المتأخر يصل باستطلاع الحالات.
const voiceFile = () => new File([new Uint8Array(900)], 'voice.webm', { type: 'audio/webm;codecs=opus' });

describe('voice notes in the outbox', () => {
    it('posts voice=1 with the recording key and shows a voice bubble', async () => {
        const calls = [];
        const post = vi.fn(async (convId, form, key) => { calls.push({ form, key }); return { status: 202, data: { row_id: 9 } }; });
        const box = createOutbox({ post, makeKey: () => 'unused', preview: () => 'blob:x' });

        expect(await box.send(4, { file: voiceFile(), kind: 'audio', voice: true, key: 'rec-key-1', seconds: 42 })).toEqual({ ok: true });

        const { form, key } = calls[0];
        expect(key).toBe('rec-key-1');
        expect([form.get('voice'), form.get('confirm_conversation_id'), form.get('file').name, form.get('doc_kind')]).toEqual(['1', '4', 'voice.webm', null]);
        expect(box.items[0]).toMatchObject({ kind: 'audio', name: 'رسالة صوتية (0:42)', state: 'queued', rowId: 9, preview: null });
    });

    it('hands a busy converter back with its code and Arabic text, and a 413 keeps the server reason', async () => {
        const refused = (status, data) => Object.assign(new Error('x'), { response: { status, data } });
        const post = vi.fn()
            .mockRejectedValueOnce(refused(503, { ok: false, code: 'CONVERTER_BUSY', error: 'المحوّل مشغول، أعد المحاولة بعد ثوانٍ.' }))
            .mockRejectedValueOnce(refused(413, { ok: false, code: 'VOICE_UPLOAD_TOO_LARGE', error: 'التسجيل أكبر من 3MB — سجّل رسالة أقصر.' }));
        const box = createOutbox({ post });

        expect(await box.send(4, { file: voiceFile(), kind: 'audio', voice: true, key: 'k', seconds: 3 }))
            .toEqual({ ok: false, status: 503, code: 'CONVERTER_BUSY', error: 'المحوّل مشغول، أعد المحاولة بعد ثوانٍ.' });
        expect((await box.send(4, { file: voiceFile(), kind: 'audio', voice: true, key: 'k', seconds: 3 })).error).toBe('التسجيل أكبر من 3MB — سجّل رسالة أقصر.');
        expect(box.items).toHaveLength(0);
    });
});

describe('applyUpdates for voice notes', () => {
    it('brings a late transcript into the open thread', () => {
        const messages = [{ id: 5, status: 'sent', body: '🎤 رسالة صوتية من الموظف (0:42)', voice: { seconds: 42, transcript: null } }];
        applyUpdates(messages, [{ id: 5, status: 'played', error: null, body: '🎤 (صوت الموظف) نرسلها اليوم.', voice: { seconds: 42, transcript: 'نرسلها اليوم.', transcript_failed: null } }]);
        expect(messages[0]).toMatchObject({ status: 'played', body: '🎤 (صوت الموظف) نرسلها اليوم.', voice: { transcript: 'نرسلها اليوم.' } });

        applyUpdates(messages, [{ id: 5, status: 'played', error: null }]);
        expect(messages[0].body).toBe('🎤 (صوت الموظف) نرسلها اليوم.');
    });
});
