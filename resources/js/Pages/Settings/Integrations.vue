<template>
  <AppLayout>
    <template #header>🔌 التكاملات</template>

    <div class="space-y-5 pb-4">
      <div v-if="pageError" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300" role="alert">❌ {{ pageError }}</div>
      <div v-if="notice" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300" role="status">✅ {{ notice }}</div>
      <div v-if="internalAllow" class="p-4 rounded-xl border text-sm border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-300" data-test="internal-allow">
        ⚠️ استثناء داخلي مفعّل من البيئة: <bdi dir="ltr" class="font-mono">{{ internalAllow }}</bdi> يُسمح له بعنوان خاص. الموصى به أن يُخاطَب المستقبل برابطه العام.
      </div>

      <div role="tablist" aria-label="أقسام التكاملات" class="flex gap-1 border-b border-gray-200 dark:border-gray-700 overflow-x-auto">
        <button v-for="t in tabs" :key="t.key" type="button" role="tab" :id="`tab-${t.key}`" :aria-selected="tab === t.key" :aria-controls="`panel-${t.key}`"
                @click="tab = t.key"
                class="shrink-0 min-h-11 px-4 -mb-px border-b-2 text-sm font-bold"
                :class="tab === t.key ? 'border-gold-500 text-gold-700 dark:text-gold-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200'">
          {{ t.label }}
        </button>
      </div>

      <!-- (1) الوجهات -->
      <section v-if="tab === 'endpoints'" id="panel-endpoints" role="tabpanel" aria-labelledby="tab-endpoints" class="space-y-4">
        <div class="p-4 rounded-xl border text-sm leading-relaxed border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-amber-900 dark:text-amber-200">
          🔒 لا نصوص رسائل ولا هواتف ولا تكاليف تخرج أبداً: أرقام الطلبات والفواتير وحالاتها فقط، بتوقيع Standard Webhooks.
          <span class="block mt-1 text-xs text-amber-800 dark:text-amber-300">الوجهة تبدأ موقوفة: أرسل ping ناجحاً ثم شغّلها. 20 فشلاً متتالياً يوقفها آلياً.</span>
        </div>
        <div class="flex justify-between items-center gap-3 flex-wrap">
          <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">الوجهات <span class="text-xs font-normal text-gray-400">({{ endpoints.length }})</span></h3>
          <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ وجهة جديدة</button>
        </div>

        <ul v-if="endpoints.length" class="space-y-3" data-test="endpoints">
          <li v-for="e in endpoints" :key="e.id" class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-3 sm:p-4 space-y-3"
              :class="e.is_active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600'">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-bold text-gray-800 dark:text-gray-100"><bdi dir="auto">{{ e.name }}</bdi></span>
              <bdi dir="ltr" class="text-xs font-mono text-gray-500 dark:text-gray-400 break-all">{{ e.host }}</bdi>
              <span class="px-2 py-0.5 rounded-full text-[11px] font-bold" :class="e.is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'">
                {{ e.is_active ? 'نشطة' : 'موقوفة' }}
              </span>
              <span v-if="!e.is_active && e.paused_reason" class="text-xs text-amber-700 dark:text-amber-400">{{ e.paused_reason }}</span>
            </div>
            <div class="flex flex-wrap gap-1">
              <span v-for="ev in e.events" :key="ev" class="px-2 py-0.5 rounded-lg text-[11px] bg-gold-50 text-gold-800 dark:bg-gold-900/20 dark:text-gold-300">{{ labels[ev] || ev }}</span>
              <span v-if="e.include_amounts" class="px-2 py-0.5 rounded-lg text-[11px] bg-blue-50 text-blue-800 dark:bg-blue-900/20 dark:text-blue-300">مع المبلغ</span>
            </div>
            <p class="text-xs text-gray-600 dark:text-gray-400 flex flex-wrap gap-x-3 gap-y-1">
              <span>آخر نجاح: <bdi dir="ltr">{{ when(e.last_success_at) }}</bdi></span>
              <span>آخر فشل: <bdi dir="ltr">{{ when(e.last_failure_at) }}</bdi></span>
              <span :class="e.consecutive_failures ? 'text-red-700 dark:text-red-400 font-bold' : ''">فشل متتالٍ: {{ e.consecutive_failures }}</span>
              <span>معلّق: {{ e.pending }} · فاشل: {{ e.dead }}</span>
              <span v-if="e.previous_secret_until" class="text-amber-700 dark:text-amber-400">السر السابق صالح حتى <bdi dir="ltr">{{ when(e.previous_secret_until) }}</bdi></span>
            </p>
            <p v-if="pingResult[e.id]" role="status" class="text-xs" :class="pingResult[e.id].ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">
              {{ pingResult[e.id].text }}
            </p>
            <div class="flex flex-wrap gap-1">
              <button type="button" @click="ping(e)" :disabled="busy === `ping-${e.id}`" class="min-h-9 px-3 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50">
                {{ busy === `ping-${e.id}` ? '⏳' : '📡 ping' }}
              </button>
              <button type="button" @click="toggle(e)" :disabled="busy === `toggle-${e.id}` || (!e.is_active && !e.last_ping_ok_at)" :title="!e.is_active && !e.last_ping_ok_at ? 'أرسل ping ناجحاً أولاً' : ''"
                      class="min-h-9 px-3 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-40">
                {{ e.is_active ? '⏸️ إيقاف' : '▶️ تشغيل' }}
              </button>
              <button type="button" @click="openForm(e)" class="min-h-9 px-3 rounded-lg text-xs font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ تعديل</button>
              <button type="button" @click="rotate(e)" class="min-h-9 px-3 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800">🔑 تدوير السر</button>
              <button type="button" @click="remove(e)" class="min-h-9 px-3 rounded-lg text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">🗑️ حذف</button>
            </div>
          </li>
        </ul>
        <div v-else class="rounded-xl border-2 border-dashed p-10 text-center space-y-3 border-gray-300 dark:border-gray-600">
          <p class="text-sm text-gray-500 dark:text-gray-400">لا وجهات بعد. المقترح: أداة الأتمتة (n8n) برابطها العام عبر Cloudflare، بحدث واحد أولاً.</p>
          <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200">+ أضف أول وجهة</button>
        </div>
      </section>

      <!-- (2) التسليمات -->
      <section v-if="tab === 'deliveries'" id="panel-deliveries" role="tabpanel" aria-labelledby="tab-deliveries">
        <DeliveriesPanel :endpoints="endpoints" :labels="labels" :counts="counts"/>
      </section>

      <!-- (3) رموز MCP -->
      <section v-if="tab === 'mcp' && mcp" id="panel-mcp" role="tabpanel" aria-labelledby="tab-mcp">
        <McpTokensPanel :tokens="mcp.tokens" :max-days="mcp.max_days" :url="mcp.url" :cloudflare="mcp.cloudflare"/>
      </section>
    </div>

    <EndpointForm :open="showForm" :endpoint="editing" :catalog="catalog" @close="showForm = false" @saved="onSaved"/>

    <OneTimeSecretModal :token="secret" title="🔑 سر التوقيع — يظهر مرة واحدة" done-label="نسخته وحفظته عند المستقبل" @close="secret = null">
      <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
        <ol class="list-decimal pr-5 space-y-1">
          <li>احفظه عند المستقبل في متغير سري (مثل <code dir="ltr">NUSK_WEBHOOK_SECRET</code> في n8n)، لا في نص العقدة.</li>
          <li>في عقدة Webhook فعّل Raw Body، ثم تحقق في عقدة Code قبل أي معالجة:</li>
        </ol>
        <pre dir="ltr" class="p-2 rounded-lg bg-gray-50 dark:bg-gray-800 text-[11px] overflow-x-auto whitespace-pre-wrap break-all" data-test="verify-snippet">{{ snippet }}</pre>
        <p class="text-xs text-gray-500 dark:text-gray-400">ارفض الطابع الأقدم من 5 دقائق، وتجاهل webhook-id مكرراً (قد يصل الحدث مرتين عند الإعادة).</p>
      </div>
    </OneTimeSecretModal>
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import OneTimeSecretModal from '@/Components/WhatsApp/OneTimeSecretModal.vue';
import EndpointForm from '@/Components/Integrations/EndpointForm.vue';
import DeliveriesPanel from '@/Components/Integrations/DeliveriesPanel.vue';
import McpTokensPanel from '@/Components/Integrations/McpTokensPanel.vue';
import { useConfirm } from '@/composables/useConfirm';
import { errorText, responseError, verifySnippet, when } from '@/composables/useIntegrations.js';

// صفحة التكاملات (E40.7): الوجهات وسرها لمرة واحدة، والتسليمات وإعادتها، ورموز MCP. الصلاحيات تُفحص في الخادم؛
// الأسرار تصل في رد JSON واحد ولا تدخل props الصفحة ولا الجلسة
const props = defineProps({
  flags: { type: Object, default: () => ({}) },
  can: { type: Object, default: () => ({}) },
  endpoints: { type: Array, default: () => [] },
  catalog: { type: Array, default: () => [] },
  counts: { type: Object, default: null },
  internalAllow: { type: String, default: null },
  mcp: { type: Object, default: null },
});

const { confirm } = useConfirm();
const endpoints = ref([...props.endpoints]);
const labels = computed(() => Object.fromEntries(props.catalog.map((e) => [e.key, e.label])));
const tabs = computed(() => [
  ...(props.can.manage ? [{ key: 'endpoints', label: 'الوجهات' }, { key: 'deliveries', label: 'التسليمات' }] : []),
  ...(props.can.mcp ? [{ key: 'mcp', label: 'رموز MCP' }] : []),
]);
const tab = ref(tabs.value[0]?.key || 'endpoints');
const showForm = ref(false);
const editing = ref(null);
const secret = ref(null);
const busy = ref(null);
const pageError = ref('');
const notice = ref('');
const pingResult = reactive({});
const snippet = verifySnippet();

function openForm(e = null) {
  editing.value = e;
  showForm.value = true;
}

function replace(endpoint) {
  const i = endpoints.value.findIndex((x) => x.id === endpoint.id);
  if (i >= 0) endpoints.value.splice(i, 1, endpoint);
  else endpoints.value.push(endpoint);
}

function onSaved(data) {
  showForm.value = false;
  replace(data.endpoint);
  if (data.secret) secret.value = data.secret;
  notice.value = data.secret ? 'أُضيفت الوجهة موقوفة. احفظ السر ثم أرسل ping.' : 'حُفظت الوجهة.';
}

async function run(key, fn) {
  busy.value = key;
  pageError.value = '';
  notice.value = '';
  try {
    return await fn();
  } catch (e) {
    pageError.value = responseError(e, 'تعذّر تنفيذ الطلب');
    return null;
  } finally {
    busy.value = null;
  }
}

async function ping(e) {
  delete pingResult[e.id];
  const data = await run(`ping-${e.id}`, async () => (await axios.post(`/settings/integrations/endpoints/${e.id}/ping`)).data);
  if (!data) return;
  if (data.endpoint) replace(data.endpoint);
  pingResult[e.id] = data.ok
    ? { ok: true, text: `✅ ردّ المستقبل ${data.status}. يمكنك تشغيل الوجهة الآن.` }
    : { ok: false, text: `❌ ${data.status ? `ردّ المستقبل ${data.status}` : errorText(data.error) || data.error}` };
}

async function toggle(e) {
  const data = await run(`toggle-${e.id}`, async () => (await axios.post(`/settings/integrations/endpoints/${e.id}/toggle`, { active: !e.is_active })).data);
  if (data?.endpoint) replace(data.endpoint);
}

async function rotate(e) {
  const ok = await confirm({
    title: `تدوير سر «${e.name}»؟`,
    message: 'يُعرض سر جديد مرة واحدة. السابق يبقى صالحاً للتوقيع 24 ساعة، فحدّث المستقبل خلالها.',
    confirmLabel: 'تدوير',
  });
  if (!ok) return;
  const data = await run(`rotate-${e.id}`, async () => (await axios.post(`/settings/integrations/endpoints/${e.id}/rotate`)).data);
  if (!data) return;
  replace(data.endpoint);
  secret.value = data.secret;
}

async function remove(e, drop = false) {
  const ok = await confirm({
    title: drop ? `حذف «${e.name}» مع إسقاط المعلّق؟` : `حذف الوجهة «${e.name}»؟`,
    message: drop ? 'التسليمات المعلّقة لن تُرسل أبداً.' : 'يُحذف سجل تسليماتها أيضاً.',
    confirmLabel: 'حذف',
    danger: true,
  });
  if (!ok) return;
  busy.value = `del-${e.id}`;
  pageError.value = '';
  try {
    await axios.delete(`/settings/integrations/endpoints/${e.id}`, { data: { drop_pending: drop } });
    endpoints.value = endpoints.value.filter((x) => x.id !== e.id);
    notice.value = 'حُذفت الوجهة.';
  } catch (err) {
    if (err?.response?.status === 409 && !drop) {
      busy.value = null;
      await remove(e, true);
      return;
    }
    pageError.value = responseError(err, 'تعذّر الحذف');
  } finally {
    busy.value = null;
  }
}
</script>
