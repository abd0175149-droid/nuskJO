<template>
  <!-- روابط ورموز عامة (E38): ملصق المكتب وصفحات التواصل، برسالة «أريد الاستفسار عن عروضكم» ومرجع الرمز -->
  <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6 space-y-3" data-test="qr-general">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">🔗 روابط ورموز عامة</h3>
      <button type="button" :disabled="busy" @click="createOpen = true"
              class="min-h-11 px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 disabled:opacity-50">➕ رمز جديد</button>
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">
      للملصقات وصفحات التواصل. رموز العروض من تبويب «📤 المشاركة» في كل عرض.
      <template v-if="limit"> المستعمل <bdi dir="ltr" class="tabular-nums">{{ limit.count }}/{{ limit.max }}</bdi> لرقمنا.</template>
    </p>
    <p class="text-[11px] text-amber-700 dark:text-amber-400">ميتا لا توفر إحصاءات للرموز. الأرقام هنا من رسائل احتفظت بالمرجع فقط.</p>
    <p v-if="error" role="alert" class="text-xs text-red-600 dark:text-red-400">❌ {{ error }}</p>
    <p v-if="notice" role="status" class="text-xs text-amber-700 dark:text-amber-400">{{ notice }}</p>
    <div v-if="links.length" class="grid grid-cols-1 xl:grid-cols-2 gap-3">
      <QrLinkCard v-for="l in links" :key="l.id" :link="l" can-manage :busy="busy" @remove="remove"/>
    </div>
    <p v-else-if="loaded" class="rounded-xl border-2 border-dashed p-6 text-center text-sm text-gray-500 border-gray-300 dark:border-gray-600">لا رموز عامة بعد.</p>

    <QrCreateDialog :open="createOpen" endpoint="/api/whatsapp/qr-links" :channels="channels"
                    @close="createOpen = false" @created="onCreated" @failed="load"/>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import QrCreateDialog from './QrCreateDialog.vue';
import QrLinkCard from './QrLinkCard.vue';
import { useConfirm } from '@/composables/useConfirm';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const { confirm } = useConfirm();
const links = ref([]);
const channels = ref({});
const limit = ref(null);
const loaded = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const createOpen = ref(false);

async function load() {
  try {
    const { data } = await axios.get('/api/whatsapp/qr-links', api);
    links.value = data.links || [];
    channels.value = data.channels || {};
    limit.value = data.limit || null;
    loaded.value = true;
    error.value = '';
  } catch (e) {
    error.value = errorText(e, 'تعذّر تحميل الرموز');
  }
}

function onCreated(data) {
  createOpen.value = false;
  notice.value = data?.warning || '';
  load();
}

async function remove(link) {
  if (!(await confirm({
    title: 'حذف الرمز',
    message: 'الملصقات المطبوعة بهذا الرمز ستتوقف عن فتح المحادثة برسالة معبأة. لا رجعة في الحذف.',
    confirmLabel: '🗑️ حذف الرمز',
    danger: true,
  }))) return;
  busy.value = true;
  try {
    await axios.delete(`/api/whatsapp/qr-links/${link.id}`, api);
    load();
  } catch (e) {
    error.value = errorText(e, 'تعذّر حذف الرمز');
  } finally {
    busy.value = false;
  }
}

onMounted(load);
</script>
