<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="تصفية التسليمات">
      <button v-for="f in FILTERS" :key="f.key" type="button" @click="setFilter(f.key)" :aria-pressed="filter === f.key"
              class="min-h-9 px-3 rounded-full text-xs font-bold border"
              :class="filter === f.key ? 'bg-gold-500 text-black border-gold-500' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'">
        {{ f.label }}<span v-if="counts && f.key && counts[f.key] !== undefined" class="ms-1 opacity-70">{{ counts[f.key] }}</span>
      </button>
      <select v-if="endpoints.length > 1" v-model="endpointId" @change="load(1)" aria-label="الوجهة"
              class="min-h-9 px-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs dark:text-gray-100">
        <option :value="null">كل الوجهات</option>
        <option v-for="e in endpoints" :key="e.id" :value="e.id">{{ e.name }}</option>
      </select>
    </div>

    <p v-if="error" class="text-sm text-red-600 dark:text-red-400" role="alert">{{ error }}</p>
    <p v-if="loading && !rows.length" class="text-sm text-gray-500 dark:text-gray-400">⏳ جارٍ التحميل…</p>

    <ul v-if="rows.length" class="space-y-2" data-test="deliveries">
      <li v-for="r in rows" :key="r.id" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 space-y-2">
        <div class="flex flex-wrap items-center gap-2">
          <span class="px-2 py-0.5 rounded-full text-[11px] font-bold" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</span>
          <span class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ eventLabel(r.event) }}</span>
          <span class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-full">← {{ r.endpoint || '—' }}</span>
          <button v-if="r.status === 'dead' || r.status === 'pending'" type="button" @click="redrive(r)" :disabled="busy === r.id"
                  class="ms-auto min-h-9 px-3 rounded-lg text-xs font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200 disabled:opacity-50">
            {{ busy === r.id ? '⏳' : '🔁 إعادة' }}
          </button>
        </div>
        <p class="text-xs text-gray-600 dark:text-gray-400 flex flex-wrap gap-x-3 gap-y-1">
          <span>المحاولات: <b>{{ r.attempts }}</b></span>
          <span v-if="r.last_status_code">آخر رمز: <b dir="ltr">{{ r.last_status_code }}</b></span>
          <span>أُنشئ: <bdi dir="ltr">{{ when(r.created_at) }}</bdi></span>
          <span v-if="r.status === 'pending' && r.next_attempt_at">المحاولة التالية: <bdi dir="ltr">{{ when(r.next_attempt_at) }}</bdi></span>
          <span v-if="r.delivered_at">سُلّم: <bdi dir="ltr">{{ when(r.delivered_at) }}</bdi></span>
        </p>
        <p v-if="r.last_error" class="text-xs text-red-700 dark:text-red-400 break-words" :title="r.last_error">{{ errorText(r.last_error) }}</p>
        <details class="text-xs">
          <summary class="cursor-pointer text-gray-500 dark:text-gray-400">الحمولة كما خرجت</summary>
          <pre dir="ltr" class="mt-1 p-2 rounded-lg bg-gray-50 dark:bg-gray-800 text-[11px] text-gray-700 dark:text-gray-300 overflow-x-auto whitespace-pre-wrap break-all">{{ JSON.stringify(r.payload, null, 2) }}</pre>
        </details>
      </li>
    </ul>
    <p v-else-if="!loading" class="rounded-xl border-2 border-dashed p-8 text-center text-sm text-gray-500 dark:text-gray-400 border-gray-300 dark:border-gray-600">لا تسليمات بهذا التصفية.</p>

    <div v-if="meta && meta.last_page > 1" class="flex items-center justify-center gap-2 text-xs">
      <button type="button" :disabled="meta.page <= 1 || loading" @click="load(meta.page - 1)" class="min-h-9 px-3 rounded-lg border border-gray-200 dark:border-gray-700 dark:text-gray-200 disabled:opacity-40">السابق</button>
      <span class="text-gray-500 dark:text-gray-400">{{ meta.page }} / {{ meta.last_page }}</span>
      <button type="button" :disabled="meta.page >= meta.last_page || loading" @click="load(meta.page + 1)" class="min-h-9 px-3 rounded-lg border border-gray-200 dark:border-gray-700 dark:text-gray-200 disabled:opacity-40">التالي</button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { errorText, responseError, statusClass, statusLabel, when } from '@/composables/useIntegrations.js';

// سجل التسليمات (E40.7): الحمولة البيضاء نفسها بلا ترويسات، والإعادة للفاشل والمعلّق وحدهما
const props = defineProps({
  endpoints: { type: Array, default: () => [] },
  labels: { type: Object, default: () => ({}) },
  counts: { type: Object, default: null },
});

const FILTERS = [
  { key: null, label: 'الكل' },
  { key: 'dead', label: 'فاشلة' },
  { key: 'pending', label: 'معلّقة' },
  { key: 'delivered', label: 'مُسلَّمة' },
];

const filter = ref('dead');
const endpointId = ref(null);
const rows = ref([]);
const meta = ref(null);
const loading = ref(false);
const busy = ref(null);
const error = ref('');

const eventLabel = (event) => props.labels[event] || (event === 'ping' ? 'اختبار الاتصال' : event);

async function load(page = 1) {
  loading.value = true;
  error.value = '';
  try {
    const params = { page };
    if (filter.value) params.status = filter.value;
    if (endpointId.value) params.endpoint = endpointId.value;
    const { data } = await axios.get('/api/integrations/deliveries', { params });
    rows.value = data.data;
    meta.value = data.meta;
  } catch (e) {
    error.value = responseError(e, 'تعذّر تحميل التسليمات');
  } finally {
    loading.value = false;
  }
}

function setFilter(key) {
  filter.value = key;
  load(1);
}

async function redrive(r) {
  busy.value = r.id;
  error.value = '';
  try {
    await axios.post(`/api/integrations/deliveries/${r.id}/redrive`);
    r.status = 'pending';
    r.attempts = 0;
    r.last_error = null;
  } catch (e) {
    error.value = responseError(e, 'تعذّرت الإعادة');
  } finally {
    busy.value = null;
  }
}

onMounted(() => load(1));
defineExpose({ load });
</script>
