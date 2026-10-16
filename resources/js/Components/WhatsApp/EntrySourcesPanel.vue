<template>
  <!-- مصادر المحادثات (E38): أعداد فقط بلا أسماء ولا أرقام. قياس داخلي تقريبي -->
  <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 space-y-3" data-test="entry-sources">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h3 class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400">📍 مصادر المحادثات</h3>
      <div class="flex gap-1" role="group" aria-label="الفترة">
        <button v-for="d in DAYS" :key="d" type="button" :aria-pressed="data.days === d" :disabled="loading" @click="load(d)"
                class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border"
                :class="data.days === d ? 'border-gold-400 bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-300' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400'">
          {{ d }} يوماً
        </button>
      </div>
    </div>

    <p class="text-[11px] text-amber-700 dark:text-amber-400">⚠️ {{ data.note }}</p>
    <p v-if="error" role="alert" class="text-xs text-red-600 dark:text-red-400">❌ {{ error }}</p>

    <div class="flex flex-wrap gap-2 text-xs">
      <span v-for="(label, key) in CHANNELS" :key="key" class="px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200">
        {{ label }}: <b class="tabular-nums">{{ data.channels?.[key] || 0 }}</b> محادثة
      </span>
    </div>

    <ul v-if="data.rows?.length" class="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
      <li v-for="(r, i) in data.rows" :key="i" class="py-2 flex flex-wrap items-center justify-between gap-2">
        <span class="min-w-0 break-words text-gray-800 dark:text-gray-100">
          <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ CHANNELS[r.channel] || r.channel }}</span>
          <template v-if="r.offer_title"> — <bdi dir="auto">{{ r.offer_title }}</bdi></template>
          <template v-if="r.qr_label"> · <bdi dir="auto">{{ r.qr_label }}</bdi></template>
          <template v-if="r.channel === 'ad' && r.headline"> — <bdi dir="auto">{{ r.headline }}</bdi></template>
          <template v-if="!r.offer_title && !r.qr_label && !r.headline"> — عام</template>
        </span>
        <span class="flex gap-3 text-xs tabular-nums text-gray-600 dark:text-gray-300">
          <span>محادثات <b>{{ r.conversations }}</b></span>
          <span>طلبات تسعير <b>{{ r.quotes }}</b></span>
          <span>محسومة <b>{{ r.won }}</b></span>
        </span>
      </li>
    </ul>
    <p v-else class="py-8 text-center text-sm text-gray-400">لا محادثات من رموز أو روابط أو إعلانات في هذه الفترة.</p>
  </section>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({ initial: { type: Object, required: true } });

const DAYS = [7, 30, 90];
const CHANNELS = { qr: '📷 رمز QR', link: '🔗 رابط', ad: '📣 إعلان' };
const data = ref({ ...props.initial });
const loading = ref(false);
const error = ref('');

async function load(days) {
  if (loading.value || data.value.days === days) return;
  loading.value = true;
  error.value = '';
  try {
    const res = await axios.get('/api/whatsapp/analytics', { params: { only: 'entries', entries_days: days }, headers: { Accept: 'application/json' } });
    if (res.data?.entries) data.value = res.data.entries;
  } catch (e) {
    error.value = errorText(e, 'تعذّر تحميل المصادر');
  } finally {
    loading.value = false;
  }
}
</script>
