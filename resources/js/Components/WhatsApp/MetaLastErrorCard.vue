<template>
  <div class="mt-4" data-test="meta-last-error">
    <p class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-2">آخر خطأ من ميتا</p>
    <div class="rounded-lg border px-3 py-2 text-sm space-y-1.5"
         :class="last && severe ? 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-gray-700'">
      <p v-if="!last" class="text-gray-500 dark:text-gray-400">لا خطأ على مستوى القناة.</p>
      <template v-else>
        <p class="font-bold break-words" :class="severe ? 'text-red-700 dark:text-red-300' : 'text-amber-800 dark:text-amber-300'">
          {{ last.message }}
          <bdi dir="ltr" class="font-mono font-normal text-xs">[{{ last.error_code }}<template v-if="last.meta_code != null"> · {{ last.meta_code }}</template>]</bdi>
        </p>
        <p v-if="last.action" class="text-gray-700 dark:text-gray-200 break-words">الإجراء: {{ last.action }}</p>
        <p class="text-xs text-gray-600 dark:text-gray-400">
          <bdi dir="ltr">{{ when(last.at) }}</bdi>
          · {{ last.count_24h }} مرة خلال 24 ساعة
          <template v-if="last.fbtrace_id"> · للدعم: <bdi dir="ltr" class="font-mono">{{ last.fbtrace_id }}</bdi></template>
        </p>
        <div class="flex items-center gap-2 flex-wrap">
          <button type="button" @click="clear" :disabled="clearing"
                  class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-xs font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40">
            {{ clearing ? '⏳ جارٍ…' : '✓ تمّ الحل' }}
          </button>
          <span class="text-[11px] text-gray-500 dark:text-gray-400">يصفّر هذا السطر فقط، ولا يرفع إيقاف الإرسال.</span>
        </div>
        <p v-if="error" role="alert" class="text-xs text-red-700 dark:text-red-400">{{ error }}</p>
      </template>
    </div>

    <ul class="mt-2 flex flex-wrap gap-1.5 text-[11px]" aria-label="أخطاء ميتا اليوم حسب الفئة">
      <li v-for="(n, c) in today" :key="c" class="px-2 py-0.5 rounded-full border"
          :class="n ? 'border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-300 font-bold' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400'">
        {{ CATEGORY_LABELS[c] || c }}: {{ n }}
      </li>
    </ul>

    <p v-if="proof" class="mt-2 text-xs" data-test="proof-line"
       :class="proofWarn ? 'text-amber-800 dark:text-amber-300 font-bold' : 'text-gray-600 dark:text-gray-400'">
      <template v-if="!proof.enabled">إثبات سر التطبيق (appsecret_proof): مطفأ — يُفعَّل بـ WA_APPSECRET_PROOF قبل «Require App Secret».</template>
      <template v-else-if="proof.percent == null">إثبات سر التطبيق: مفعّل، ولا نداءات خلال {{ proof.days }} أيام.</template>
      <template v-else>
        إثبات سر التطبيق: مُرسل في {{ proof.percent }}% من {{ proof.calls }} نداء منذ {{ proof.days }} أيام<template v-if="proof.proof_err"> — ⛔ رفضته ميتا {{ proof.proof_err }} مرة</template>.
        <template v-if="proof.ready_for_require"> جاهز لتفعيل Require App Secret.</template>
      </template>
    </p>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { when } from '@/utils/waSettings.js';

const props = defineProps({
  last: { type: Object, default: null },
  today: { type: Object, default: () => ({}) },
  proof: { type: Object, default: null },
});
const emit = defineEmits(['cleared']);

const CATEGORY_LABELS = {
  auth: 'اعتماد', integrity: 'تقييد', window: 'النافذة', throttle: 'خنق', transient: 'مؤقت',
  quality: 'جودة', caller: 'الطلب', unknown: 'غير معروف',
};

const severe = computed(() => ['auth', 'integrity'].includes(props.last?.category));
const proofWarn = computed(() => !!props.proof?.enabled && (props.proof.proof_err > 0 || (props.proof.percent != null && props.proof.percent < 100)));

const clearing = ref(false);
const error = ref('');

async function clear() {
  clearing.value = true;
  error.value = '';
  try {
    await axios.post('/api/whatsapp/settings/last-error/clear', {}, { headers: { Accept: 'application/json' } });
    emit('cleared');
  } catch (e) {
    error.value = e?.response?.data?.message && /[؀-ۿ]/.test(e.response.data.message) ? e.response.data.message : 'تعذّر التصفير';
  } finally {
    clearing.value = false;
  }
}
</script>
