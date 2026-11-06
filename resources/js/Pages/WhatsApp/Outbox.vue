<template>
  <AppLayout>
    <template #header>طابور الإرسال</template>

    <div class="space-y-4 pb-4">
      <p class="text-sm text-gray-600 dark:text-gray-300">رسائل النظام والمتابعات المجدولة قبل خروجها. «مؤجلة» تعني أن حداً أوقفها (نافذة الساعات أو سقف أو الفاصل) وتخرج وحدها حين يسمح. ردود البوت والموظفين لا تمر من هنا.</p>

      <div class="flex flex-wrap gap-2" role="tablist">
        <button v-for="(label, k) in TABS" :key="k" type="button" role="tab" :aria-selected="tab === k" @click="setTab(k)"
                class="px-3 py-1.5 rounded-lg text-sm font-bold" :class="tab === k ? 'bg-gold-100 text-gold-800 dark:bg-gold-900/30 dark:text-gold-200' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800'">
          {{ label }} <span class="text-xs font-normal">({{ counts[k] || 0 }})</span>
        </button>
      </div>

      <div class="flex flex-wrap gap-2 text-sm">
        <select v-model="filters.channel_id" @change="load(true)" aria-label="الرقم" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
          <option :value="null">كل الأرقام</option>
          <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <select v-model="filters.type_key" @change="load(true)" aria-label="النوع" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
          <option :value="null">كل الأنواع</option>
          <option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option>
        </select>
        <select v-model="filters.test" @change="load(true)" aria-label="الاختبار" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
          <option :value="null">الكل</option>
          <option value="0">للعملاء</option>
          <option value="1">تجارب</option>
        </select>
      </div>

      <p v-if="notice" role="status" class="p-3 rounded-xl text-sm" :class="notice.ok ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-300' : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300'">{{ notice.text }}</p>

      <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 dark:text-gray-400 text-xs text-start">
            <th class="p-2 text-start">النوع</th><th class="p-2 text-start">العميل</th><th class="p-2 text-start">الرقم</th>
            <th class="p-2 text-start">{{ tab === 'sent' ? 'أُرسلت' : 'الموعد' }}</th><th class="p-2 text-start">السبب</th><th class="p-2"></th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-for="r in rows" :key="r.id">
              <td class="p-2"><span class="font-bold">{{ r.type }}</span> <span v-if="r.is_test" class="text-xs px-1.5 rounded bg-teal-50 text-teal-700">🧪</span></td>
              <td class="p-2">{{ r.client?.name || '—' }} <span v-if="r.phone_tail" class="text-xs text-gray-400 font-mono" dir="ltr">…{{ r.phone_tail }}</span>
                <a v-if="r.conversation_id" :href="'/whatsapp/inbox?conv=' + r.conversation_id" class="text-xs text-gold-700 ms-1">المحادثة</a></td>
              <td class="p-2"><span v-if="r.channel" class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full" :style="{ background: r.channel.color }"></span>{{ r.channel.name }}</span><span v-else class="text-gray-400">يُختار عند الإرسال</span></td>
              <td class="p-2 whitespace-nowrap"><bdi>{{ fmt(tab === 'sent' ? r.sent_at : r.due_at) }}</bdi></td>
              <td class="p-2 text-xs text-gray-600 dark:text-gray-300 max-w-[260px]">{{ r.error || r.reason || '' }}</td>
              <td class="p-2 whitespace-nowrap text-end">
                <button v-if="r.status === 'awaiting_approval'" type="button" @click="act(r, 'approve')" class="px-2 py-1 rounded-lg text-xs font-bold text-white bg-green-600">اعتماد</button>
                <button v-if="['pending', 'deferred'].includes(r.status)" type="button" @click="act(r, 'send_now')" class="px-2 py-1 rounded-lg text-xs font-bold text-gold-700 hover:bg-gold-50">أرسل الآن</button>
                <button v-if="['failed', 'expired'].includes(r.status)" type="button" @click="act(r, 'retry')" class="px-2 py-1 rounded-lg text-xs font-bold text-gold-700 hover:bg-gold-50">أعد المحاولة</button>
                <button v-if="['pending', 'deferred', 'awaiting_approval'].includes(r.status)" type="button" @click="act(r, 'cancel')" class="px-2 py-1 rounded-lg text-xs font-bold text-red-600 hover:bg-red-50">إلغاء</button>
              </td>
            </tr>
            <tr v-if="!rows.length && !loading"><td colspan="6" class="p-6 text-center text-gray-400">لا رسائل هنا</td></tr>
          </tbody>
        </table>
      </div>
      <div class="text-center"><button v-if="next" type="button" @click="load(false)" class="px-4 py-2 rounded-xl text-sm font-bold bg-gray-100 dark:bg-gray-800">المزيد</button></div>
    </div>
  </AppLayout>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  counts: { type: Object, default: () => ({}) },
  channels: { type: Array, default: () => [] },
  types: { type: Object, default: () => ({}) },
});

const TABS = { upcoming: 'القادمة', deferred: 'المؤجلة', approval: 'تنتظر الموافقة', sent: 'أُرسلت', problems: 'لم تُرسل', cancelled: 'ملغاة' };
const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const tab = ref('upcoming');
const rows = ref([]);
const next = ref(null);
const loading = ref(false);
const notice = ref(null);
const filters = reactive({ channel_id: null, type_key: null, test: null });
const fmt = (iso) => (iso ? new Date(iso).toLocaleString('ar-JO', { dateStyle: 'short', timeStyle: 'short' }) : '—');

async function load(reset) {
  loading.value = true;
  try {
    const params = { tab: tab.value, ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== null)) };
    if (!reset && next.value) params.before = next.value;
    const { data } = await axios.get('/api/whatsapp/outbox', { ...api, params });
    rows.value = reset ? data.rows : [...rows.value, ...data.rows];
    next.value = data.next;
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر التحميل') };
  } finally {
    loading.value = false;
  }
}

function setTab(k) {
  tab.value = k;
  next.value = null;
  load(true);
}

async function act(r, action) {
  try {
    await axios.post(`/api/whatsapp/outbox/${r.id}/${action}`, {}, api);
    notice.value = { ok: true, text: 'تم' };
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر تنفيذ الإجراء') };
  }
  load(true);
}

onMounted(() => load(true));
</script>
