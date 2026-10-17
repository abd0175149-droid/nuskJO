<template>
  <div data-test="account-events">
    <p class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-2">سجل أحداث ميتا</p>
    <p v-if="!events.length" class="text-xs text-gray-500 dark:text-gray-400">لا أحداث حساب بعد.</p>
    <ul v-else class="divide-y divide-gray-100 dark:divide-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 text-xs">
      <li v-for="e in events" :key="e.id" class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2">
        <bdi dir="ltr" class="text-gray-500 dark:text-gray-400 shrink-0">{{ amman(e.at) }}</bdi>
        <span class="text-gray-700 dark:text-gray-200">{{ EVENT_FIELDS[e.field] || e.field }}</span>
        <bdi dir="ltr" class="font-mono text-gray-600 dark:text-gray-300 break-all">{{ e.event }}</bdi>
        <span class="px-1.5 py-0.5 rounded-full border text-[11px] font-bold" :class="TONES[(SEVERITIES[e.severity] || SEVERITIES.info).tone]">
          {{ (SEVERITIES[e.severity] || SEVERITIES.info).label }}
        </span>
        <span class="text-gray-600 dark:text-gray-400">← {{ EFFECTS[e.effect] || e.effect }}</span>
        <span v-if="!e.handled" class="text-amber-700 dark:text-amber-400 font-bold" title="لم يكتمل أثره؛ يُعاد مع الفحص التالي">⏳</span>
        <button v-if="e.effect !== 'ignored_foreign'" type="button" @click="show(e)" :aria-label="`عرض حمولة الحدث ${e.event}`"
                class="ms-auto min-h-9 px-2.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 font-bold">
          عرض
        </button>
      </li>
    </ul>

    <AppModal :open="!!open" :title="open ? `${EVENT_FIELDS[open.field] || open.field} · ${open.event}` : ''" size="lg" @close="open = null">
      <p v-if="loading" class="text-sm text-gray-500 dark:text-gray-400">⏳ جارٍ التحميل…</p>
      <p v-else-if="error" role="alert" class="text-sm text-red-700 dark:text-red-400">{{ error }}</p>
      <template v-else-if="payload">
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">الحمولة كما وصلت من ميتا بعد حجب الأسرار. الوقت: <bdi dir="ltr">{{ amman(payload.occurred_at || payload.created_at) }}</bdi></p>
        <pre dir="ltr" class="text-xs font-mono whitespace-pre-wrap break-all bg-gray-50 dark:bg-gray-800 rounded-lg p-3 max-h-[50dvh] overflow-auto">{{ pretty }}</pre>
      </template>
    </AppModal>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import { EFFECTS, EVENT_FIELDS, SEVERITIES, TONES, amman } from '@/utils/channelHealth.js';

// آخر 30 حدث حساب (E22): الحقل والحدث والخطورة والأثر، والحمولة لصف واحد عند الطلب (مسار للمدير وحده)
defineProps({
  events: { type: Array, default: () => [] },
});

const open = ref(null);
const payload = ref(null);
const loading = ref(false);
const error = ref('');
const pretty = computed(() => JSON.stringify(payload.value?.payload ?? null, null, 2));

async function show(e) {
  open.value = e;
  payload.value = null;
  error.value = '';
  loading.value = true;
  try {
    const { data } = await axios.get(`/api/whatsapp/channel-health/events/${e.id}`, { headers: { Accept: 'application/json' } });
    payload.value = data;
  } catch {
    error.value = 'تعذّر تحميل الحمولة';
  } finally {
    loading.value = false;
  }
}
</script>
