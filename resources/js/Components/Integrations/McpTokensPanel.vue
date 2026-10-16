<template>
  <div class="space-y-4">
    <div v-if="!cloudflare" class="p-3 rounded-xl text-xs leading-relaxed border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-300" data-test="no-cloudflare">
      ⛔ Cloudflare Access غير مضبوط (MCP_CF_TEAM_DOMAIN وMCP_CF_AUD): الخادم يرد 404 على الإنتاج حتى يُضبط.
    </div>
    <p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">
      رمز للقراءة فقط (mcp:read) يستعمله Claude Code لقراءة تقاريرك: المبيعات والاعتمادات والمسافرون وطلبات التسعير والعملاء،
      بلا أي محتوى من واتساب ولا هواتف. كل استدعاء في سجل التدقيق. الرابط: <bdi dir="ltr" class="font-mono">{{ url }}</bdi>
    </p>

    <form @submit.prevent="create" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-3 flex flex-wrap items-end gap-3">
      <div class="flex-1 min-w-[10rem]">
        <label for="mcp-name" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">اسم الرمز</label>
        <input id="mcp-name" v-model="name" maxlength="60" required placeholder="مثال: حاسوب المكتب"
               class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-gray-100 text-sm"/>
      </div>
      <div>
        <label for="mcp-days" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">المدة</label>
        <select id="mcp-days" v-model.number="days" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-gray-100 text-sm">
          <option v-for="d in dayOptions" :key="d" :value="d">{{ d }} يوماً</option>
        </select>
      </div>
      <button type="submit" :disabled="busy || !name.trim()" class="min-h-10 px-4 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
        {{ busy ? '⏳' : '🔑 إنشاء رمز' }}
      </button>
    </form>
    <p v-if="error" class="text-sm text-red-600 dark:text-red-400" role="alert">{{ error }}</p>

    <ul v-if="list.length" class="space-y-2" data-test="tokens">
      <li v-for="t in list" :key="t.id" class="rounded-xl border bg-white dark:bg-gray-900 p-3 flex flex-wrap items-center gap-x-3 gap-y-1"
          :class="t.expired ? 'border-dashed border-gray-300 dark:border-gray-600' : 'border-gray-200 dark:border-gray-700'">
        <span class="text-sm font-bold text-gray-800 dark:text-gray-100"><bdi dir="auto">{{ t.name }}</bdi></span>
        <span v-if="t.owner" class="text-xs text-gray-500 dark:text-gray-400">({{ t.owner }})</span>
        <span v-if="t.expired" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">منتهٍ</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">آخر استعمال: <bdi dir="ltr">{{ when(t.last_used_at) }}</bdi></span>
        <span class="text-xs text-gray-500 dark:text-gray-400">ينتهي: <bdi dir="ltr">{{ when(t.expires_at) }}</bdi></span>
        <button type="button" @click="revoke(t)" class="ms-auto min-h-9 px-3 rounded-lg text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">⛔ إبطال</button>
      </li>
    </ul>
    <p v-else class="rounded-xl border-2 border-dashed p-6 text-center text-sm text-gray-500 dark:text-gray-400 border-gray-300 dark:border-gray-600">لا رموز بعد.</p>

    <OneTimeSecretModal :token="plain" title="🔑 رمز MCP الجديد — يظهر مرة واحدة" done-label="نسخته وحفظته في ملف .mcp.json" @close="plain = null">
      <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
        <p>احفظه في ملف <code dir="ltr">.mcp.json</code> محلي خارج أي مستودع، مع قيمتي Cloudflare من المالك:</p>
        <pre dir="ltr" class="p-2 rounded-lg bg-gray-50 dark:bg-gray-800 text-[11px] overflow-x-auto whitespace-pre-wrap break-all" data-test="mcp-config">{{ config }}</pre>
        <p class="text-xs text-gray-500 dark:text-gray-400">لا تجمعه في جلسة واحدة مع خوادم تملك أدوات كتابة أو إرسال.</p>
      </div>
    </OneTimeSecretModal>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import OneTimeSecretModal from '@/Components/WhatsApp/OneTimeSecretModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { mcpConfigSnippet, responseError, when } from '@/composables/useIntegrations.js';

// رموز خادم MCP (E40.9): الإنشاء خلف التحقق المعزّز (يعيد المعترض الطلب بعد التأكيد)، والنص الصريح مرة واحدة
const props = defineProps({
  tokens: { type: Array, default: () => [] },
  maxDays: { type: Number, default: 30 },
  url: { type: String, default: '' },
  cloudflare: Boolean,
});

const { confirm } = useConfirm();
const list = ref([...props.tokens]);
const name = ref('');
const days = ref(Math.min(7, props.maxDays));
const busy = ref(false);
const error = ref('');
const plain = ref(null);
const dayOptions = computed(() => [1, 7, 14, props.maxDays].filter((d, i, a) => d <= props.maxDays && a.indexOf(d) === i));
const config = computed(() => mcpConfigSnippet(props.url, plain.value || undefined));

async function create() {
  busy.value = true;
  error.value = '';
  try {
    const { data } = await axios.post('/settings/integrations/mcp-tokens', { name: name.value.trim(), days: days.value });
    list.value.unshift(data.item);
    plain.value = data.token;
    name.value = '';
  } catch (e) {
    error.value = responseError(e, 'تعذّر إنشاء الرمز');
  } finally {
    busy.value = false;
  }
}

async function revoke(t) {
  const ok = await confirm({ title: `إبطال الرمز «${t.name}»؟`, message: 'يتوقف كل عميل يستعمله فوراً.', confirmLabel: 'إبطال', danger: true });
  if (!ok) return;
  try {
    await axios.delete(`/settings/integrations/mcp-tokens/${t.id}`);
    list.value = list.value.filter((x) => x.id !== t.id);
  } catch (e) {
    error.value = responseError(e, 'تعذّر الإبطال');
  }
}
</script>
