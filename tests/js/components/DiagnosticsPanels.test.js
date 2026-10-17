import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ChannelTestPanel from '@/Components/WhatsApp/ChannelTestPanel.vue';
import TestSendPanel from '@/Components/WhatsApp/TestSendPanel.vue';

// أدوات التشخيص (E23): «اختبار الاتصال» بخطواته، و«إرسال تجريبي» للمعلَّمين وحدهم ومتابعة علامات التسليم
const steps = [
    { key: 'node', ok: true, label: 'الرمز ومعرّف الرقم', detail: 'متطابقان.', advice: null },
    { key: 'app_secret', ok: false, label: 'App Secret ورابط الويبهوك', detail: 'الويبهوك يشير إلى aibot-platform.example.com وليس إلى نُسك.', advice: 'صحّحه من App Dashboard' },
    { key: 'waba', ok: null, label: 'اشتراك حساب الأعمال', detail: 'معرّف حساب الأعمال غير معروف.', advice: null },
];
const recipients = [
    { id: 3, name: 'المالك', last4: '1234', has_conversation: true, window_open: true, minutes_left: 300 },
    { id: 4, name: 'المحاسب', last4: '5678', has_conversation: true, window_open: false, minutes_left: null },
];

describe('ChannelTestPanel', () => {
    afterEach(() => vi.restoreAllMocks());

    it('runs the test and marks each step with its explanation', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: false, steps } });
        const w = mount(ChannelTestPanel);

        await w.find('[data-test="channel-test-run"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/whatsapp/channel-test', {}, expect.anything());
        const rows = w.findAll('[data-test="channel-step"]');
        expect(rows).toHaveLength(3);
        expect(rows[0].text()).toContain('✓');
        expect(rows[1].text()).toContain('✗');
        expect(rows[1].text()).toContain('وليس إلى نُسك');
        expect(rows[1].text()).toContain('💡 صحّحه من App Dashboard');
        expect(rows[2].text()).toContain('–');
        expect(w.text()).toContain('لا يثبت صحته');
    });

    it('explains the rate limit', async () => {
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 429 } });
        const w = mount(ChannelTestPanel);
        await w.find('[data-test="channel-test-run"]').trigger('click');
        await flushPromises();
        expect(w.find('[role="alert"]').text()).toContain('ست مرات في الدقيقة');
    });
});

describe('TestSendPanel', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('asks to mark a number when none is marked', () => {
        const w = mount(TestSendPanel, { props: { recipients: [] } });
        expect(w.text()).toContain('لا رقم معلَّماً بعد');
        expect(w.find('[data-test="test-send-run"]').exists()).toBe(false);
    });

    it('disables sending to a closed window and says why', async () => {
        const w = mount(TestSendPanel, { props: { recipients } });
        await w.find('#ts-to').setValue(4);
        expect(w.find('[data-test="test-send-run"]').attributes('disabled')).toBeDefined();
        expect(w.text()).toContain('لا نرسل خارج نافذة 24 ساعة');
    });

    it('sends only the chosen kind with an idempotency key, then follows the ticks', async () => {
        vi.useFakeTimers();
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ status: 200, data: { ok: true, code: null, meta_code: null, label: null, message_id: 77, wamid: 'wamid.X' } });
        const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { id: 77, status: 'read' } });
        const w = mount(TestSendPanel, { props: { recipients, perHour: 10 } });

        await w.find('#ts-kind').setValue('buttons');
        await w.find('[data-test="test-send-run"]').trigger('click');
        await flushPromises();

        const [url, body, config] = post.mock.calls[0];
        expect(url).toBe('/api/whatsapp/test-send');
        expect(body).toEqual({ admin_number_id: 3, kind: 'buttons' });
        expect(config.headers['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/);
        expect(w.find('[data-test="test-send-result"]').text()).toContain('قبلتها ميتا');

        await vi.advanceTimersByTimeAsync(5000);
        await flushPromises();
        expect(get).toHaveBeenCalledWith('/api/whatsapp/test-send/77/status', expect.anything());
        expect(w.find('[data-test="test-send-result"]').html()).toContain('قُرئت');
    });

    it('shows a refusal with its code', async () => {
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status: 422, data: { code: 'WINDOW_EXPIRED', error: 'اطلب من صاحب الرقم مراسلة رقم الشركة أولاً' } } });
        const w = mount(TestSendPanel, { props: { recipients } });
        await w.find('[data-test="test-send-run"]').trigger('click');
        await flushPromises();
        const result = w.find('[data-test="test-send-result"]').text();
        expect(result).toContain('لم تُرسل');
        expect(result).toContain('WINDOW_EXPIRED');
        expect(result).toContain('HTTP 422');
    });
});
