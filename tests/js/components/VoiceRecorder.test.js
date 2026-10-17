import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import Composer from '@/Components/WhatsApp/Composer.vue';
import MessageBubble from '@/Components/WhatsApp/MessageBubble.vue';
import VoiceNote from '@/Components/WhatsApp/VoiceNote.vue';
import { fakeEnv } from '../whatsapp/voiceEnv.js';

// الرسالة الصوتية في المنشئ (E34.7) وفي الخيط (E34.8): الزر بشروطه، والتسجيل والمعاينة والتأكيد، والإرسال بمفتاح التسجيل،
// و«المحوّل مشغول» يُبقي التسجيل، وEsc وتغيير المحادثة يطفئان الميكروفون. المشغّل لا يحمّل شيئاً قبل الضغط.
const voicePolicy = { enabled: true, max_seconds: 120, max_bytes: 3000000 };
const recipient = { name: 'أبو سالم', last4: '1234', clientCode: 'C-9' };
const mounted = [];

function composer(props = {}) {
    const env = props.voiceEnv ?? fakeEnv();
    const send = props.sendVoice ?? vi.fn(async () => ({ ok: true }));
    const w = mount(Composer, {
        props: { modelValue: '', canReply: true, windowOpen: true, finePointer: true, conversationId: 3, recipient, voicePolicy, ...props, voiceEnv: env, sendVoice: send },
        attachTo: document.body,
    });
    mounted.push(w);
    return { w, env, send };
}
const mic = (w) => w.find('[data-test="voice-record"]');

async function recordAndStop(w) {
    await mic(w).trigger('click');
    await flushPromises();
    await w.find('[data-test="voice-stop"]').trigger('click');
    await flushPromises();
}

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    document.body.innerHTML = '';
    vi.useRealTimers();
});

describe('Composer voice button', () => {
    it('shows only with the policy, an open window, browser support and a reply permission', () => {
        expect(mic(composer().w).exists()).toBe(true);
        expect(mic(composer({ windowOpen: false }).w).exists()).toBe(false);
        expect(mic(composer({ voicePolicy: null }).w).exists()).toBe(false);
        expect(mic(composer({ voicePolicy: { ...voicePolicy, enabled: false } }).w).exists()).toBe(false);
        expect(mic(composer({ voiceEnv: { navigator: {} } }).w).exists()).toBe(false);
        expect(mic(composer({ canReply: false }).w).exists()).toBe(false);
    });
});

describe('VoiceRecorder flow', () => {
    it('records with a visible timer, previews, confirms the recipient and sends with the recording key', async () => {
        vi.useFakeTimers();
        const { w, env, send } = composer();
        await mic(w).trigger('click');
        await flushPromises();

        const timer = w.find('[data-test="voice-timer"]');
        expect(timer.attributes('aria-live')).toBe('polite');
        vi.advanceTimersByTime(42_000);
        await nextTick();
        expect(timer.text()).toBe('0:42 / 2:00');
        expect(mic(w).attributes('disabled')).toBeDefined();

        await w.find('[data-test="voice-stop"]').trigger('click');
        await flushPromises();
        const preview = w.find('[data-test="voice-preview"]');
        expect(preview.attributes('src')).toBe('blob:voice-1');
        expect(env.track.stop).toHaveBeenCalled();

        await w.find('[data-test="voice-send"]').trigger('click');
        await nextTick();
        const dialog = document.querySelector('dialog');
        expect(dialog.textContent).toContain('أبو سالم');
        expect(dialog.textContent).toContain('…1234');
        expect(dialog.textContent).toContain('0:42');
        expect(send).not.toHaveBeenCalled();

        dialog.querySelector('[data-test="voice-confirm"]').click();
        await flushPromises();
        expect(send).toHaveBeenCalledTimes(1);
        const payload = send.mock.calls[0][0];
        expect([payload.conversationId, payload.seconds, typeof payload.key, payload.file.name]).toEqual([3, 42, 'string', 'voice.webm']);
        expect(w.find('[data-test="voice-panel"]').exists()).toBe(false);
        expect(env.URL.revokeObjectURL).toHaveBeenCalledWith('blob:voice-1');
    });

    it('keeps the recording and its key when the converter is busy, and sends again with the same key', async () => {
        const send = vi.fn()
            .mockResolvedValueOnce({ ok: false, status: 503, code: 'CONVERTER_BUSY', error: 'المحوّل مشغول، أعد المحاولة بعد ثوانٍ.' })
            .mockResolvedValueOnce({ ok: true });
        const { w } = composer({ sendVoice: send });
        await recordAndStop(w);

        for (let i = 0; i < 2; i++) {
            await w.find('[data-test="voice-send"]').trigger('click');
            await nextTick();
            document.querySelector('[data-test="voice-confirm"]').click();
            await flushPromises();
            if (i === 0) {
                expect(w.find('[data-test="voice-error"]').text()).toContain('المحوّل مشغول');
                expect(w.find('[data-test="voice-send"]').text()).toContain('أعد الإرسال');
                expect(w.find('[data-test="voice-preview"]').exists()).toBe(true);
            }
        }
        expect(send.mock.calls[0][0].key).toBe(send.mock.calls[1][0].key);
        expect(w.find('[data-test="voice-panel"]').exists()).toBe(false);
    });

    it('delete turns the microphone off and frees the preview', async () => {
        const { w, env, send } = composer();
        await recordAndStop(w);
        await w.find('[data-test="voice-delete"]').trigger('click');

        expect(env.URL.revokeObjectURL).toHaveBeenCalledWith('blob:voice-1');
        expect(env.track.stop).toHaveBeenCalled();
        expect(w.find('[data-test="voice-panel"]').exists()).toBe(false);
        expect(mic(w).attributes('disabled')).toBeUndefined();
        expect(send).not.toHaveBeenCalled();
    });

    it('Escape cancels a running recording, and a conversation change discards it', async () => {
        const { w, env } = composer();
        await mic(w).trigger('click');
        await flushPromises();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(w.find('[data-test="voice-panel"]').exists()).toBe(false);
        expect(env.track.stop).toHaveBeenCalledTimes(1);

        await recordAndStop(w);
        await w.setProps({ conversationId: 4 });
        await nextTick();
        expect(w.find('[data-test="voice-panel"]').exists()).toBe(false);
        expect(env.URL.revokeObjectURL).toHaveBeenCalled();
    });

    it('leaves the text draft alone', async () => {
        const { w } = composer({ modelValue: 'مسودة نصية' });
        await recordAndStop(w);
        await w.find('[data-test="voice-delete"]').trigger('click');
        expect(w.emitted('update:modelValue')).toBeUndefined();
        expect(w.find('textarea').element.value).toBe('مسودة نصية');
    });

    it('says why when the microphone is refused', async () => {
        const { w } = composer({ voiceEnv: fakeEnv({ deny: 'NotAllowedError' }) });
        await mic(w).trigger('click');
        await flushPromises();
        expect(w.find('[data-test="voice-error"]').text()).toContain('لم يُسمح باستعمال الميكروفون');
    });
});

describe('VoiceNote in the thread', () => {
    const media = { kind: 'audio', status: 'stored', mime: 'audio/ogg', viewable: true };

    it('plays from the scoped media route only on demand, with the duration and a folded transcript', () => {
        const w = mount(VoiceNote, { props: { messageId: 77, media, voice: { seconds: 42, transcript: 'التأشيرة جاهزة.', transcript_failed: null } } });
        const audio = w.find('[data-test="voice-player"]');
        expect(audio.attributes('preload')).toBe('none');
        expect(audio.attributes('src')).toBe('/api/whatsapp/messages/77/media');
        expect(w.text()).toContain('0:42');
        const details = w.find('[data-test="voice-transcript"]');
        expect(details.element.tagName).toBe('DETAILS');
        expect(details.element.open).toBe(false);
        expect(details.text()).toContain('التأشيرة جاهزة.');
    });

    it('explains a missing transcript and hides the player without the media permission', () => {
        const w = mount(VoiceNote, { props: { messageId: 77, media: { ...media, viewable: false }, voice: { seconds: 5, transcript: null, transcript_failed: 'terms' } } });
        expect(w.find('[data-test="voice-player"]').exists()).toBe(false);
        expect(w.find('[data-test="voice-locked"]').exists()).toBe(true);
        expect(w.find('[data-test="voice-transcript-failed"]').text()).toContain('تأكيد المدير');
    });

    it('the bubble does not repeat the transcript as text', () => {
        const m = { id: 77, direction: 'out', source: 'staff', type: 'audio', body: '🎤 (صوت الموظف) التأشيرة جاهزة.', status: 'played', at: '2026-10-16 10:00',
            sender: 'سارة', voice: { seconds: 42, transcript: 'التأشيرة جاهزة.' }, multi_fail: false };
        const w = mount(MessageBubble, { props: { m } });
        expect(w.text()).not.toContain('(صوت الموظف)');
        expect(w.find('[data-status="played"]').text()).toBe('▶️ سُمعت');
    });
});
