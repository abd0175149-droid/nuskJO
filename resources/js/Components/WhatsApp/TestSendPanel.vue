<template>
    <div data-test="test-send" class="mt-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4 text-sm">
        <p class="font-bold text-gray-800 dark:text-gray-100">🧪 إرسال تجريبي</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            إلى أرقام الإدارة المعلَّمة «يستقبل الرسائل التجريبية» وحدها، بنافذة 24 ساعة مفتوحة، ونصوص ثابتة. حد {{ perHour }} رسائل في الساعة للنظام كله.
            رسالة خدمة: تُحتسب ضمن الألف المجانية شهرياً لكل رقم.
        </p>

        <p v-if="!recipients.length" class="text-xs text-amber-700 dark:text-amber-400 mt-2">لا رقم معلَّماً بعد — علّم رقمك من صفحة «أرقام الإدارة».</p>
        <div v-else class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-2 items-end">
            <div class="min-w-0">
                <label for="ts-to" class="block text-xs text-gray-600 dark:text-gray-300 mb-1">المستلم</label>
                <select id="ts-to" v-model="to" class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white">
                    <option v-for="r in recipients" :key="r.id" :value="r.id">{{ r.name }} (…{{ r.last4 }}) — {{ windowText(r) }}</option>
                </select>
            </div>
            <div class="min-w-0">
                <label for="ts-kind" class="block text-xs text-gray-600 dark:text-gray-300 mb-1">النوع</label>
                <select id="ts-kind" v-model="kind" class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white">
                    <option v-for="(label, k) in KINDS" :key="k" :value="k">{{ label }}</option>
                </select>
            </div>
            <button type="button" data-test="test-send-run" :disabled="sending || !selected?.window_open" @click="send"
                    class="px-4 py-2 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-40 disabled:cursor-not-allowed">
                {{ sending ? '⏳ جارٍ الإرسال…' : '📤 أرسل' }}
            </button>
        </div>
        <p v-if="selected && !selected.window_open" class="text-xs text-amber-700 dark:text-amber-400 mt-2">اطلب من صاحب الرقم مراسلة رقم الشركة أولاً — لا نرسل خارج نافذة 24 ساعة ولا قوالب.</p>

        <div v-if="result" data-test="test-send-result" role="status" class="mt-3 rounded-lg border px-3 py-2"
             :class="result.ok ? 'border-green-200 dark:border-green-900/60 bg-white dark:bg-gray-900' : 'border-red-200 dark:border-red-900/60 bg-red-50 dark:bg-red-900/20'">
            <p class="font-bold" :class="result.ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">
                {{ result.ok ? '✅ قبلتها ميتا' : '❌ لم تُرسل' }}
                <DeliveryTicks v-if="status?.status" :status="status.status" class="ms-1"/>
            </p>
            <p v-if="result.label || result.error" class="text-xs text-gray-700 dark:text-gray-300">{{ result.label || result.error }}</p>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 break-all">
                <template v-if="result.http">HTTP {{ result.http }} · </template>
                <template v-if="result.code">الرمز <bdi dir="ltr">{{ result.code }}</bdi> · </template>
                <template v-if="result.meta_code">ميتا <bdi dir="ltr">{{ result.meta_code }}</bdi> · </template>
                <template v-if="result.wamid"><bdi dir="ltr">{{ result.wamid }}</bdi></template>
            </p>
            <p v-if="polling" class="text-[11px] text-gray-400">يتابع علامات التسليم…</p>
        </div>
    </div>
</template>

<script setup>
// «إرسال تجريبي» (E23، العلم wa_test_send): نصوص ثابتة من الخادم، ثم علامات التسليم (E21) كل 5 ثوانٍ لدقيقتين
import { computed, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';
import DeliveryTicks from '@/Components/WhatsApp/DeliveryTicks.vue';

const props = defineProps({
    recipients: { type: Array, default: () => [] },
    perHour: { type: Number, default: 10 },
});

const KINDS = { text: 'نص', image: 'صورة', buttons: 'أزرار', list: 'قائمة' };
const POLL_MS = 5000;
const POLL_FOR_MS = 120000;

const to = ref(props.recipients[0]?.id ?? null);
const kind = ref('text');
const sending = ref(false);
const result = ref(null);
const status = ref(null);
const polling = ref(false);
const selected = computed(() => props.recipients.find((r) => r.id === to.value));
let timer = null;

const windowText = (r) => (r.window_open ? `النافذة مفتوحة (${Math.floor((r.minutes_left || 0) / 60)}س)` : (r.has_conversation ? 'النافذة مغلقة' : 'بلا محادثة'));

function stopPolling() {
    clearInterval(timer);
    timer = null;
    polling.value = false;
}

function poll(id) {
    stopPolling();
    const until = Date.now() + POLL_FOR_MS;
    polling.value = true;
    timer = setInterval(async () => {
        if (Date.now() > until) return stopPolling();
        try {
            const { data } = await axios.get(`/api/whatsapp/test-send/${id}/status`, { headers: { Accept: 'application/json' } });
            status.value = data;
            if (['read', 'failed'].includes(data.status)) stopPolling();
        } catch {
            stopPolling();
        }
    }, POLL_MS);
}

async function send() {
    sending.value = true;
    status.value = null;
    stopPolling();
    try {
        const key = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : undefined;
        const { data, status: http } = await axios.post('/api/whatsapp/test-send', { admin_number_id: to.value, kind: kind.value },
            { headers: { Accept: 'application/json', ...(key ? { 'Idempotency-Key': key } : {}) } });
        result.value = { ...data, http };
        if (data.ok && data.message_id) poll(data.message_id);
    } catch (e) {
        const res = e?.response;
        result.value = { ok: false, http: res?.status, code: res?.data?.code, error: res?.data?.error || (res?.status === 429 ? 'تجاوزت الحد — حاول لاحقاً.' : 'تعذّر الإرسال.') };
    } finally {
        sending.value = false;
    }
}

onBeforeUnmount(stopPolling);
</script>
