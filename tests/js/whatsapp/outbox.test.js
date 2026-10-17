import { describe, expect, it, vi } from 'vitest';
import { createOutbox } from '@/composables/whatsapp/useOutbox.js';

// الفقاعات المتفائلة لملفات الموظف (E32.10): مفتاح لكل محاولة، ثابت بعد انقطاع الشبكة، ويُزال بعد رد صريح من الخادم
const pdf = () => new File([new Uint8Array(64)], 'visa.pdf', { type: 'application/pdf' });
const jpg = () => new File([new Uint8Array(64)], 'photo.jpg', { type: 'image/jpeg' });
const refused = (status, data = {}) => Object.assign(new Error('refused'), { response: { status, data } });
const offline = () => new Error('Network Error');

function setup(responses) {
    const calls = [];
    let n = 0;
    let k = 0;
    const post = vi.fn(async (convId, form, key, onProgress) => {
        calls.push({ convId, form, key });
        onProgress?.({ loaded: 50, total: 100 });
        const r = responses[n++];
        if (r instanceof Error) throw r;
        return r;
    });
    const revoke = vi.fn();
    const box = createOutbox({ post, makeKey: () => `key-${++k}`, preview: () => 'blob:preview', revoke });
    return { box, post, calls, revoke };
}

describe('createOutbox', () => {
    it('shows a bubble at once with upload progress, then queued on 202, posting the confirmed conversation', async () => {
        const { box, calls } = setup([{ status: 202, data: { ok: true, status: 'queued', row_id: 77, warning: 'ملاحظة' } }]);
        const pending = box.send(5, { file: pdf(), kind: 'document', caption: 'تأشيرتك', docKind: 'visa', confirmIdentity: true, replyTo: 9 });
        expect(box.items).toHaveLength(1);
        expect(box.items[0].state).toBe('uploading');
        expect(box.items[0].progress).toBe(50);

        expect(await pending).toEqual({ ok: true });
        const item = box.items[0];
        expect(item.state).toBe('queued');
        expect(item.rowId).toBe(77);
        expect(item.warning).toBe('ملاحظة');

        const form = calls[0].form;
        expect(calls[0].convId).toBe(5);
        expect(calls[0].key).toBe('key-1');
        expect(form.get('confirm_conversation_id')).toBe('5');
        expect(form.get('caption')).toBe('تأشيرتك');
        expect(form.get('doc_kind')).toBe('visa');
        expect(form.get('confirm_identity')).toBe('1');
        expect(form.get('reply_to_message_id')).toBe('9');
        expect(form.get('ack_warnings')).toBeNull();
        expect(form.get('file').name).toBe('visa.pdf');

        expect(box.forConversation(5)).toHaveLength(1);
        expect(box.forConversation(6)).toHaveLength(0);
    });

    it('retries with the same key after a network failure only', async () => {
        const { box, calls, post } = setup([offline(), { status: 202, data: { row_id: 3 } }]);
        const first = await box.send(5, { file: pdf(), kind: 'document', docKind: 'other' });
        expect(first.network).toBe(true);
        const item = box.items[0];
        expect(item.state).toBe('network');
        expect(item.error).toContain('انقطع الاتصال');

        expect(await box.retry(item)).toEqual({ ok: true });
        expect(calls.map((c) => c.key)).toEqual(['key-1', 'key-1']);
        expect(item.state).toBe('queued');

        // لا إعادة لما وصل الخادم
        expect(await box.retry(item)).toEqual({ ok: false });
        expect(post).toHaveBeenCalledTimes(2);
    });

    it('removes the bubble on a server refusal; the next file gets a new key', async () => {
        const { box, calls } = setup([
            refused(422, { error: 'نوع الملف غير مسموح', code: 'MEDIA_TYPE', action: 'حوّله إلى PDF' }),
            { status: 202, data: { row_id: 8 } },
        ]);
        const res = await box.send(5, { file: pdf(), kind: 'document', docKind: 'other' });
        expect(res).toMatchObject({ ok: false, status: 422, code: 'MEDIA_TYPE', error: 'نوع الملف غير مسموح — حوّله إلى PDF' });
        expect(box.items).toHaveLength(0);

        await box.send(5, { file: pdf(), kind: 'document', docKind: 'other' });
        expect(calls[1].key).toBe('key-2');
    });

    it('sends with ack_warnings only after the policy warning is confirmed', async () => {
        const warn = { code: 'POLICY_WARN', error: 'التعليق فيه رقم هاتف' };
        const { box, calls } = setup([refused(409, warn), { status: 202, data: { row_id: 4 } }]);
        const ask = vi.fn(async () => true);
        expect(await box.send(5, { file: pdf(), kind: 'document', docKind: 'other', caption: 'اتصل بي' }, ask)).toEqual({ ok: true });
        expect(ask).toHaveBeenCalledWith('التعليق فيه رقم هاتف');
        expect(calls[1].form.get('ack_warnings')).toBe('1');
        expect(calls[1].key).toBe(calls[0].key);

        const declined = setup([refused(409, warn)]);
        const res = await declined.box.send(5, { file: pdf(), kind: 'document', docKind: 'other' }, async () => false);
        expect(res).toMatchObject({ ok: false, status: 409, code: 'POLICY_WARN' });
        expect(declined.box.items).toHaveLength(0);
    });

    it('drops the bubble once its row shows in that thread, and frees the preview', async () => {
        const { box, revoke } = setup([{ status: 202, data: { row_id: 11 } }]);
        await box.send(5, { file: jpg(), kind: 'image', docKind: 'other' });
        expect(box.items[0].preview).toBe('blob:preview');

        box.reconcile(5, [{ id: 10 }]);
        box.reconcile(6, [{ id: 11 }]);
        expect(box.items).toHaveLength(1);

        box.reconcile(5, [{ id: 10 }, { id: 11 }]);
        expect(box.items).toHaveLength(0);
        expect(revoke).toHaveBeenCalledWith('blob:preview');
    });

    it('shows a replayed, already sent file as sent, and dismiss removes a failed bubble', async () => {
        const { box } = setup([{ status: 200, data: { row_id: 2, replayed: true } }, offline()]);
        await box.send(5, { file: pdf(), kind: 'document', docKind: 'other' });
        expect(box.items[0].state).toBe('sent');

        await box.send(5, { file: pdf(), kind: 'document', docKind: 'other' });
        box.dismiss(box.items[1]);
        expect(box.items).toHaveLength(1);
    });

    it('turns server errors into Arabic text', async () => {
        const cases = [
            [refused(413, { message: 'Payload Too Large' }), 'الملف أكبر من حد الخادم.'],
            [refused(403, { message: 'This action is unauthorized.' }), 'ليست لديك صلاحية لهذا الإجراء'],
            [refused(422, { errors: { caption: ['التعليق طويل'] } }), 'التعليق طويل'],
            [refused(500, {}), 'تعذّر إرسال الملف'],
        ];
        for (const [error, text] of cases) {
            const { box } = setup([error]);
            expect((await box.send(1, { file: pdf(), kind: 'document', docKind: 'other' })).error).toBe(text);
        }
    });
});
