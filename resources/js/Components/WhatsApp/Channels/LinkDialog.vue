<template>
  <AppModal :open="!!channel" :title="channel ? `ربط «${channel.name}» بهاتف` : ''" size="md" @close="$emit('close')">
    <div v-if="channel" class="space-y-4 text-sm">
      <div class="flex gap-2" role="tablist">
        <button type="button" role="tab" :aria-selected="mode === 'qr'" @click="mode = 'qr'"
                class="px-3 py-1.5 rounded-lg font-bold" :class="mode === 'qr' ? 'bg-gold-100 text-gold-800 dark:bg-gold-900/30 dark:text-gold-200' : 'text-gray-500'">رمز QR</button>
        <button type="button" role="tab" :aria-selected="mode === 'code'" @click="mode = 'code'"
                class="px-3 py-1.5 rounded-lg font-bold" :class="mode === 'code' ? 'bg-gold-100 text-gold-800 dark:bg-gold-900/30 dark:text-gold-200' : 'text-gray-500'">كود بالرقم</button>
      </div>

      <div v-if="linked" class="p-4 rounded-xl bg-green-50 text-green-800 dark:bg-green-900/20 dark:text-green-300 font-bold text-center">
        ✅ رُبط الرقم <span dir="ltr" class="font-mono">+{{ channel.phone }}</span>
      </div>

      <template v-else-if="mode === 'qr'">
        <ol class="list-decimal ps-5 space-y-1 text-gray-600 dark:text-gray-300">
          <li>افتح واتساب على هاتف الرقم.</li>
          <li>الإعدادات ← الأجهزة المرتبطة ← ربط جهاز.</li>
          <li>وجّه الكاميرا إلى الرمز. يتجدد الرمز وحده كل نحو 20 ثانية.</li>
        </ol>
        <div class="flex items-center justify-center min-h-[280px] rounded-xl border border-gray-200 dark:border-gray-700 bg-white">
          <img v-if="qr" :src="qr" alt="رمز QR لربط الرقم" class="w-64 h-64" />
          <p v-else class="text-gray-500 p-6 text-center">{{ waitingText }}</p>
        </div>
      </template>

      <template v-else>
        <p class="text-gray-600 dark:text-gray-300">اكتب رقم الهاتف كاملاً بالمقدمة الدولية، ثم أدخل الكود في واتساب: الأجهزة المرتبطة ← ربط جهاز ← «الربط برقم الهاتف بدلاً من ذلك».</p>
        <form class="flex gap-2" @submit.prevent="requestCode">
          <input v-model="phone" dir="ltr" inputmode="tel" placeholder="9627XXXXXXXX" aria-label="رقم الهاتف"
                 class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 font-mono" />
          <button type="submit" :disabled="busy" class="px-4 py-2 rounded-lg font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">طلب الكود</button>
        </form>
        <p v-if="code" class="text-center text-3xl font-mono font-bold tracking-[0.3em] py-4" dir="ltr">{{ code }}</p>
      </template>

      <p v-if="error" role="alert" class="p-3 rounded-lg bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300">{{ error }}</p>
      <p class="text-xs text-gray-400">الحالة: {{ statusLabel(status) }}</p>
    </div>
  </AppModal>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import { errorText } from '@/composables/whatsapp/useInbox.js';
import { statusLabel } from '@/composables/whatsapp/useChannels.js';

const props = defineProps({ channel: { type: Object, default: null } });
const emit = defineEmits(['close', 'changed']);

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const mode = ref('qr');
const qr = ref(null);
const status = ref('');
const phone = ref('');
const code = ref('');
const error = ref('');
const busy = ref(false);
const linked = computed(() => status.value === 'ready');
const waitingText = computed(() => ['created', 'initializing', 'disconnected', 'failed', ''].includes(status.value)
  ? 'تبدأ الجلسة… يظهر الرمز خلال ثوانٍ.' : 'بانتظار رمز جديد…');
let timer = null;

// استطلاع كل 3 ثوانٍ (لا انتظار طويل: حد Cloudflare 100 ثانية)، والبث يسرّعه من الصفحة
async function poll() {
  if (!props.channel) return;
  try {
    const { data } = await axios.get(`/api/whatsapp/channels/${props.channel.id}/qr`, api);
    qr.value = data.qr || null;
    status.value = data.channel?.status || data.status || '';
    error.value = '';
    if (status.value === 'ready') {
      stop();
      emit('changed', data.channel);
    }
  } catch (e) {
    error.value = errorText(e, 'تعذّر جلب رمز الربط');
  }
}

function start() {
  stop();
  status.value = props.channel?.status || '';
  poll();
  timer = setInterval(poll, 3000);
}

function stop() {
  if (timer) clearInterval(timer);
  timer = null;
}

async function requestCode() {
  busy.value = true;
  error.value = '';
  try {
    const { data } = await axios.post(`/api/whatsapp/channels/${props.channel.id}/pairing`, { phone: phone.value }, api);
    code.value = data.pairing_code || '';
  } catch (e) {
    error.value = errorText(e, 'تعذّر طلب الكود');
  } finally {
    busy.value = false;
  }
}

watch(() => props.channel?.id, (id) => {
  qr.value = null;
  code.value = '';
  error.value = '';
  mode.value = 'qr';
  id ? start() : stop();
}, { immediate: true });

defineExpose({ poll });
onBeforeUnmount(stop);
</script>
