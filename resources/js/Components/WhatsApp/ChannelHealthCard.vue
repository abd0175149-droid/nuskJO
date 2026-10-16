<template>
  <div id="channel-health" class="mt-4 scroll-mt-20" data-test="channel-health">
    <p class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-2">حالة القناة (آلة الحالات)</p>
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-3 text-sm space-y-3">
      <p v-if="!health.enabled" class="text-xs text-gray-500 dark:text-gray-400" data-test="health-off">
        آلة صحة القناة مطفأة (العلم channel_health) — الإيقاف الآلي القديم يعمل. الحجب القائم يُرفع من هنا.
      </p>

      <!-- رأس الحالة -->
      <div class="flex flex-wrap items-center gap-2">
        <span data-test="health-badge" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full border text-xs font-bold" :class="TONES[state.tone]">
          {{ state.icon }} {{ state.label }}
        </span>
        <span v-if="health.source_label" class="text-xs text-gray-600 dark:text-gray-400">المصدر: {{ health.source_label }}</span>
        <span v-if="health.until" class="text-xs text-gray-600 dark:text-gray-400">حتى <bdi dir="ltr">{{ amman(health.until) }}</bdi></span>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ health.checked_at ? `آخر فحص ${ago(health.checked_at)}` : 'لم يُفحص بعد' }}</span>
      </div>
      <p v-if="health.reason" class="text-xs text-gray-700 dark:text-gray-200 break-words" data-test="health-reason">
        السبب: <template v-if="reasonParts.note">{{ reasonParts.note }} · </template><bdi dir="ltr" class="font-mono text-[11px] text-gray-500 dark:text-gray-400 break-all">{{ reasonParts.code }}</bdi>
      </p>

      <!-- الإيقاف اليدوي محور مستقل -->
      <p v-if="health.manual?.on" data-test="manual-line" class="text-xs font-bold text-red-700 dark:text-red-400 break-words">
        ⛔ موقوف يدوياً<template v-if="health.manual.by_name"> بواسطة {{ health.manual.by_name }}</template>: {{ health.manual.reason || (SUSPEND_SOURCES[health.manual.source] || health.manual.source) }}
        <span class="font-normal">(الإيقاف الطارئ أعلى الصفحة)</span>
      </p>

      <p v-if="health.state === 'TOKEN_INVALID' && !health.block_on_token" data-test="token-bar"
         class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
        الإرسال لا يُمنع تلقائياً، لكن ميتا سترفضه حتى يُجدَّد الرمز. احفظ رمزاً جديداً في «ربط قناة ميتا» ثم اضغط «افحص الآن».
      </p>

      <!-- اقتراح الرفع: لا رفع آلياً أبداً -->
      <div v-if="health.unblock_suggested" data-test="suggestion"
           class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 px-3 py-2 space-y-2">
        <p class="text-sm font-bold text-green-800 dark:text-green-300">
          {{ health.state === 'BLOCKED' ? '✅ ميتا تشير إلى رفع القيد' : '✅ الرمز يعمل الآن — اضغط «افحص الآن» أعلى البطاقة' }}
        </p>
        <p class="text-xs text-green-800 dark:text-green-300">القفل باقٍ حتى تقرّر: راجع البريد من ميتا ولوحة <bdi dir="ltr">Business Support Home</bdi> أولاً.</p>
        <div class="flex flex-wrap gap-2">
          <button v-if="health.state === 'BLOCKED'" type="button" @click="openUnblock" class="min-h-10 px-4 rounded-xl text-sm font-bold bg-green-600 text-white hover:bg-green-700">ارفع القفل</button>
          <button type="button" @click="acknowledge" :disabled="busy" class="min-h-10 px-4 rounded-xl text-sm font-bold border border-green-600 text-green-700 dark:text-green-400 hover:bg-green-100 dark:hover:bg-green-900/30 disabled:opacity-40">تم الاطلاع</button>
        </div>
      </div>
      <button v-else-if="health.state === 'BLOCKED'" type="button" @click="openUnblock"
              class="min-h-10 px-4 rounded-xl text-sm font-bold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20">
        ارفع القفل…
      </button>
      <p v-if="message" role="status" class="text-xs" :class="messageOk ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">{{ message }}</p>

      <!-- الجودة والحد والملاحظات -->
      <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-700 dark:text-gray-200">
        <li v-if="meta.quality_rating">الجودة: <span class="px-1.5 py-0.5 rounded-full border font-bold" :class="TONES[(QUALITY[meta.quality_rating] || { tone: 'gray' }).tone]">{{ (QUALITY[meta.quality_rating] || { label: meta.quality_rating }).label }}</span></li>
        <li v-if="meta.tier">الحد: <bdi dir="ltr" class="font-mono">{{ meta.tier }}</bdi> <span class="text-gray-500 dark:text-gray-400">(يخص الرسائل التي تبدؤها الشركة فقط)</span></li>
        <li v-if="health.consecutive_failures > 0" class="font-bold text-amber-700 dark:text-amber-400" data-test="consecutive">فشل إرسال متتالٍ: {{ health.consecutive_failures }}</li>
        <li v-if="health.foreign_today > 0" class="text-gray-500 dark:text-gray-400">أحداث لحساب أو رقم آخر اليوم: {{ health.foreign_today }} (تُتجاهل)</li>
      </ul>
      <p v-if="meta.possible_solution" class="text-xs text-gray-600 dark:text-gray-400 break-words">الحل المقترح من ميتا: <bdi dir="ltr">{{ meta.possible_solution }}</bdi></p>
      <p v-if="meta.waba_match === false" class="text-xs font-bold text-amber-700 dark:text-amber-400">⚠️ معرّف حساب الأعمال لا يطابق فحص ميتا — راجع WA_WABA_ID.</p>
      <p v-if="health.breaker_lock" class="text-xs text-gray-500 dark:text-gray-400">قاطع النزاهة يحجب الإرسال (integrity_breaker_lock).</p>

      <AccountEventsTable :events="health.events || []"/>
    </div>

    <AppModal :open="unblockOpen" title="رفع حجب الإرسال" size="md" :dirty="!!reason" @close="unblockOpen = false">
      <form @submit.prevent="submitUnblock" class="space-y-3">
        <p class="text-sm text-gray-700 dark:text-gray-200">يُفحص الحساب من ميتا أولاً؛ إن كانت ما زالت تحجب الرقم يُرفض الرفع. الرفع يُسجَّل بالسبب والفاعل.</p>
        <div>
          <label for="ch-reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السبب (10 أحرف على الأقل)</label>
          <textarea id="ch-reason" data-autofocus v-model="reason" rows="2" required minlength="10" maxlength="500"
                    class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"></textarea>
        </div>
        <div>
          <label for="ch-confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اكتب «رفع» للتأكيد</label>
          <input id="ch-confirm" v-model="confirmText" required autocomplete="off"
                 class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
        </div>
        <p v-if="formError" role="alert" class="text-xs text-red-700 dark:text-red-400 break-words">{{ formError }}</p>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" @click="unblockOpen = false" class="min-h-10 px-4 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
          <button type="submit" :disabled="busy || confirmText.trim() !== 'رفع' || reason.trim().length < 10"
                  class="min-h-10 px-5 rounded-xl text-sm font-bold bg-green-600 text-white disabled:opacity-40">{{ busy ? '⏳' : 'رفع' }}</button>
        </div>
      </form>
    </AppModal>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import AccountEventsTable from '@/Components/WhatsApp/AccountEventsTable.vue';
import { QUALITY, TONES, ago, amman, healthState } from '@/utils/channelHealth.js';
import { SUSPEND_SOURCES } from '@/utils/waSettings.js';

// صحة القناة كآلة حالات (E22) داخل بطاقة «صحة النظام والقناة»: الحالة والسبب والمصدر والانتهاء، والإيقاف اليدوي منفصلاً،
// واقتراح الرفع بزرين، والرفع بسبب وكتابة «رفع» (الخادم يفحص ميتا قبله). «افحص الآن» زر البطاقة الأم (meta-check).
const props = defineProps({
  health: { type: Object, required: true },
});
const emit = defineEmits(['changed']);

const state = computed(() => healthState(props.health.state));
const meta = computed(() => props.health.meta || {});
// السبب «رمز — ملاحظة» (ChannelHealth::transition): الملاحظة العربية أولاً، والرمز الداخلي صغيراً باتجاه LTR
const reasonParts = computed(() => {
  const [code, ...rest] = String(props.health.reason || '').split(' — ');
  return { code, note: rest.join(' — ') };
});

const unblockOpen = ref(false);
const reason = ref('');
const confirmText = ref('');
const formError = ref('');
const busy = ref(false);
const message = ref('');
const messageOk = ref(false);

const errorText = (e, fallback) => {
  const data = e?.response?.data || {};
  const first = Object.values(data.errors || {})[0]?.[0];
  const text = first || data.message;
  return text && /[؀-ۿ]/.test(text) ? text + (data.possible_solution ? ` — ${data.possible_solution}` : '') : fallback;
};

function openUnblock() {
  reason.value = '';
  confirmText.value = '';
  formError.value = '';
  unblockOpen.value = true;
}

async function submitUnblock() {
  busy.value = true;
  formError.value = '';
  try {
    const { data } = await axios.post('/api/whatsapp/channel-health/unblock', { reason: reason.value, confirm_text: confirmText.value }, { headers: { Accept: 'application/json' } });
    emit('changed', data.channelHealth);
    unblockOpen.value = false;
    messageOk.value = true;
    message.value = '✅ رُفع الحجب';
  } catch (e) {
    formError.value = e?.response?.status === 429 ? 'محاولات كثيرة — انتظر دقيقة' : errorText(e, 'تعذّر الرفع');
  } finally {
    busy.value = false;
  }
}

async function acknowledge() {
  busy.value = true;
  message.value = '';
  try {
    const { data } = await axios.post('/api/whatsapp/channel-health/acknowledge', {}, { headers: { Accept: 'application/json' } });
    emit('changed', data.channelHealth);
  } catch (e) {
    messageOk.value = false;
    message.value = errorText(e, 'تعذّر الحفظ');
  } finally {
    busy.value = false;
  }
}
</script>
