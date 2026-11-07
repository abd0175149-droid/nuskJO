<template>
  <AppLayout>
    <template #header>أرقام واتساب</template>

    <div class="space-y-6 pb-4">
      <div class="p-4 rounded-xl border text-sm leading-relaxed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 text-gray-600 dark:text-gray-300 space-y-1.5">
        <p>كل رقم هنا هاتف واتساب مربوط ببوابة نُسك كجهاز مرتبط. العميل الذي يراسل رقمين تُفتح له محادثتان، والرد يخرج دائماً من رقم المحادثة نفسها.</p>
        <p>الموظفون المسندون إلى رقم وحدهم يرون محادثاته (ومن يرى كل المحادثات). رقم بلا موظفين مسندين مفتوح للجميع بقيد الاختصاصات.</p>
        <p>«متصل» يعني أن الفحص الحقيقي نجح خلال دقائق. «ready» من الهاتف وحدها لا تكفي، فالجلسة قد تعلق بعد إعادة تشغيل.</p>
      </div>

      <div class="flex justify-between items-center gap-3 flex-wrap">
        <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">الأرقام <span class="text-sm font-normal text-gray-400">({{ list.length }})</span></h3>
        <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ رقم جديد</button>
      </div>

      <div v-if="list.length" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <article v-for="c in list" :key="c.id" class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-4 space-y-3"
                 :class="c.is_active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600 opacity-70'">
          <header class="flex items-start justify-between gap-3 flex-wrap">
            <div class="min-w-0 flex items-start gap-2">
              <span class="mt-1 w-3 h-3 rounded-full shrink-0" :style="{ background: c.color }" aria-hidden="true"></span>
              <div class="min-w-0">
                <p class="font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2 flex-wrap">
                  {{ c.name }}
                  <span class="px-2 py-0.5 rounded-full text-[11px] font-bold" :class="statusTone(c)">{{ c.paused_until ? 'موقوف مؤقتاً' : (c.ready ? 'متصل' : statusLabel(c.status)) }}</span>
                  <span v-if="c.breaker_until" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">قاطع الإخفاقات</span>
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                  <span v-if="c.phone" class="font-mono" dir="ltr">+{{ c.phone }}</span><span v-else>غير مربوط</span>
                  <span v-if="c.push_name"> · {{ c.push_name }}</span>
                  <span v-if="c.last_probe_ok_at"> · آخر فحص ناجح <bdi>{{ ago(c.last_probe_ok_at) }}</bdi></span>
                </p>
              </div>
            </div>
            <div class="flex items-center gap-1 shrink-0 flex-wrap">
              <button v-if="!c.linked" type="button" @click="linking = c" class="px-2 py-1 rounded-lg text-xs font-bold text-white bg-green-600 hover:bg-green-700">🔗 ربط</button>
              <button type="button" @click="openForm(c)" class="px-2 py-1 rounded-lg text-xs font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ تعديل</button>
              <details class="relative">
                <summary class="list-none cursor-pointer px-2 py-1 rounded-lg text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="أوامر الرقم">⋯</summary>
                <div class="absolute z-10 end-0 mt-1 w-48 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg p-1 text-xs">
                  <button v-for="a in actionsFor(c)" :key="a.cmd" type="button" @click="run(c, a)"
                          class="w-full text-start px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800" :class="a.danger ? 'text-red-600 dark:text-red-400' : ''">{{ a.label }}</button>
                  <button type="button" @click="showEvents(c)" class="w-full text-start px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">📜 سجل الجلسة</button>
                </div>
              </details>
            </div>
          </header>

          <div class="grid grid-cols-3 gap-2 text-center">
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-2"><p class="text-lg font-bold">{{ c.today.in }}</p><p class="text-[11px] text-gray-500">وارد اليوم</p></div>
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-2"><p class="text-lg font-bold">{{ c.today.out }}</p><p class="text-[11px] text-gray-500">صادر اليوم</p></div>
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-2"><p class="text-lg font-bold" :class="c.today.failed ? 'text-red-600' : ''">{{ c.today.failed }}</p><p class="text-[11px] text-gray-500">فشل اليوم</p></div>
          </div>

          <div class="flex flex-wrap gap-1.5 text-xs">
            <span v-for="r in c.roles" :key="r" class="px-2 py-0.5 rounded-full font-bold bg-gold-50 text-gold-800 dark:bg-gold-900/30 dark:text-gold-200">{{ ROLE_LABELS[r] || r }}</span>
            <span class="px-2 py-0.5 rounded-full font-bold bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ BOT_MODE_LABELS[c.bot_mode] || c.bot_mode }}</span>
            <span v-for="g in c.is_default_for" :key="g" class="px-2 py-0.5 rounded-full font-bold bg-sky-50 text-sky-800 dark:bg-sky-900/30 dark:text-sky-300">افتراضي: {{ GROUP_LABELS[g] || g }}</span>
          </div>
          <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ c.conversations }} محادثة ·
            <template v-if="c.users.length">يخدمه: {{ c.users.map((u) => u.name + (u.primary ? ' ★' : '')).join('، ') }}</template>
            <template v-else>مفتوح لكل الموظفين بقيد الاختصاصات</template>
          </p>
          <!-- استيراد سجل الهاتف (HistoryImport): يبدأ وحده عند أول ربط، ويُستأنف من حيث توقف -->
          <div v-if="c.linked || c.history?.status" class="text-xs p-2 rounded-lg bg-gray-50 dark:bg-gray-800/40 space-y-1" data-test="history-import">
            <div class="flex items-center justify-between gap-2 flex-wrap">
              <span class="font-bold text-gray-700 dark:text-gray-200">📥 سجل الهاتف: {{ historyLabel(c.history) }}</span>
              <span class="flex gap-1">
                <button v-if="c.history?.status === 'running'" type="button" @click="run(c, { cmd: 'import_stop', label: '⏹ إيقاف الاستيراد' })"
                        class="px-2 py-0.5 rounded-lg font-bold text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-gray-600">⏹ إيقاف</button>
                <button v-else-if="c.linked" type="button" @click="run(c, importAction(c))"
                        class="px-2 py-0.5 rounded-lg font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200">📥 {{ importAction(c).short }}</button>
              </span>
            </div>
            <div v-if="c.history?.status === 'running' && c.history.chats_total" class="h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden" role="progressbar"
                 :aria-valuenow="c.history.chats_done" aria-valuemin="0" :aria-valuemax="c.history.chats_total">
              <div class="h-full bg-gold-400" :style="{ width: Math.min(100, Math.round(100 * c.history.chats_done / c.history.chats_total)) + '%' }"></div>
            </div>
            <p class="text-gray-500 dark:text-gray-400">
              {{ c.history?.chats_done || 0 }} / {{ c.history?.chats_total ?? '…' }} محادثة · {{ c.history?.messages || 0 }} رسالة ·
              العمق: آخر {{ c.history?.days }} يوماً، حتى {{ c.history?.messages_per_chat }} رسالة للمحادثة
            </p>
            <p v-if="c.history?.chats_partial" class="text-gray-500 dark:text-gray-400">{{ c.history.chats_partial }} محادثة وصلتها رسائل قبل دورها: أُخذ ما بعد أحدثها وحده، فلا يختلط ترتيب الخيط. والمحادثة المستوردة كلها تُعاد بناؤها كاملة عند «استيراد من جديد».</p>
            <p v-if="c.history?.chats_skipped" class="text-amber-700 dark:text-amber-300">{{ c.history.chats_skipped }} محادثة تعذّرت قراءتها من الهاتف فتُخطّيت.</p>
            <p v-if="c.history?.last_error && c.history.status !== 'done'" class="text-red-600 dark:text-red-300">آخر خطأ: {{ c.history.last_error }}</p>
          </div>
          <p v-if="c.paused_until" class="text-xs p-2 rounded-lg bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
            ⏸ موقوف حتى <bdi>{{ fmt(c.paused_until) }}</bdi><template v-if="c.pause_reason === 'restriction'"> — علامة تقييد من واتساب، راجع الهاتف قبل رفع الإيقاف</template>
          </p>
          <p v-if="!c.provisioned" class="text-xs p-2 rounded-lg bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300">الرقم لم يُعدّ على البوابة بعد (جلسة أو مفتاح أو webhook ناقص). اختر «إعادة الإعداد».</p>
        </article>
      </div>
      <div v-else class="rounded-xl border-2 border-dashed p-10 text-center space-y-3 border-gray-300 dark:border-gray-600">
        <p class="text-sm text-gray-500 dark:text-gray-400">لا أرقام بعد. أضف رقم المبيعات ثم اربطه بالهاتف.</p>
        <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200">+ أضف رقماً</button>
      </div>
      <p v-if="notice" role="status" class="p-3 rounded-xl text-sm" :class="notice.ok ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-300' : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300'">{{ notice.text }}</p>
    </div>

    <!-- إضافة / تعديل -->
    <AppModal :open="showForm" :title="editing ? `تعديل «${editing.name}»` : 'رقم جديد'" size="lg" @close="showForm = false">
      <form id="channel-form" class="space-y-4 text-sm" @submit.prevent="save">
        <label class="block"><span class="font-bold">الاسم كما يظهر للموظفين</span>
          <input v-model="form.name" required maxlength="60" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" placeholder="المبيعات" />
        </label>
        <fieldset><legend class="font-bold mb-1">دور الرقم</legend>
          <div class="flex flex-wrap gap-3">
            <label v-for="r in roles" :key="r" class="flex items-center gap-1.5"><input type="checkbox" :value="r" v-model="form.roles" /> {{ ROLE_LABELS[r] || r }}</label>
          </div>
        </fieldset>
        <fieldset><legend class="font-bold mb-1">اللون في الصندوق</legend>
          <div class="flex gap-2">
            <button v-for="p in palette" :key="p" type="button" @click="form.color = p" :aria-label="'اللون ' + p" :aria-pressed="form.color === p"
                    class="w-7 h-7 rounded-full border-2" :class="form.color === p ? 'border-gray-900 dark:border-white' : 'border-transparent'" :style="{ background: p }"></button>
          </div>
        </fieldset>
        <template v-if="editing">
          <fieldset><legend class="font-bold mb-1">البوت على هذا الرقم</legend>
            <div class="flex flex-wrap gap-3">
              <label v-for="m in botModes" :key="m" class="flex items-center gap-1.5"><input type="radio" :value="m" v-model="form.bot_mode" /> {{ BOT_MODE_LABELS[m] || m }}</label>
            </div>
          </fieldset>
          <fieldset><legend class="font-bold mb-1">الرقم الافتراضي لرسائل النظام</legend>
            <div class="flex flex-wrap gap-3">
              <label v-for="(label, g) in GROUP_LABELS" :key="g" class="flex items-center gap-1.5"><input type="checkbox" :value="g" v-model="form.is_default_for" /> {{ label }}</label>
            </div>
          </fieldset>
          <fieldset><legend class="font-bold mb-1">الموظفون الذين يخدمون الرقم</legend>
            <p class="text-xs text-gray-500 mb-2">★ الأساسي: تُنسب إليه الرسائل التي تُكتب من هاتف الرقم مباشرة.</p>
            <div class="max-h-48 overflow-y-auto grid grid-cols-1 sm:grid-cols-2 gap-1.5">
              <div v-for="u in staff" :key="u.id" class="flex items-center gap-2">
                <label class="flex items-center gap-1.5 flex-1"><input type="checkbox" :value="u.id" v-model="form.users" /> {{ u.name }}</label>
                <button v-if="form.users.includes(u.id)" type="button" @click="form.primary = u.id" :aria-pressed="form.primary === u.id"
                        class="text-xs px-1.5 rounded" :class="form.primary === u.id ? 'text-gold-700 font-bold' : 'text-gray-400'">★</button>
              </div>
            </div>
          </fieldset>
        </template>
        <p v-if="formError" role="alert" class="p-3 rounded-lg bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false" class="px-4 py-2 rounded-xl font-bold text-gray-600 dark:text-gray-300">إلغاء</button>
        <button type="submit" form="channel-form" :disabled="busy" class="px-4 py-2 rounded-xl font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">{{ editing ? 'حفظ' : 'إضافة وبدء الجلسة' }}</button>
      </template>
    </AppModal>

    <!-- سجل الجلسة -->
    <AppModal :open="!!eventsOf" :title="eventsOf ? `سجل جلسة «${eventsOf.name}»` : ''" size="lg" @close="eventsOf = null">
      <ul class="text-sm divide-y divide-gray-100 dark:divide-gray-800">
        <li v-for="e in events" :key="e.id" class="py-2 flex justify-between gap-3">
          <span>{{ EVENT_LABELS[e.event] || e.event }}<span v-if="e.detail?.to" class="text-gray-400"> ← {{ statusLabel(e.detail.to) }}</span></span>
          <bdi class="text-xs text-gray-400 shrink-0">{{ fmt(e.created_at) }}</bdi>
        </li>
        <li v-if="!events.length" class="py-4 text-center text-gray-400">لا أحداث بعد</li>
      </ul>
    </AppModal>

    <LinkDialog ref="linkDialog" :channel="linking" @close="linking = null" @changed="onLinked" />
  </AppLayout>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import AppModal from '@/Components/AppModal.vue';
import LinkDialog from '@/Components/WhatsApp/Channels/LinkDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { errorText } from '@/composables/whatsapp/useInbox.js';
import { BOT_MODE_LABELS, GROUP_LABELS, ROLE_LABELS, statusLabel, statusTone, useChannelEvents } from '@/composables/whatsapp/useChannels.js';

const props = defineProps({
  channels: { type: Array, default: () => [] },
  staff: { type: Array, default: () => [] },
  roles: { type: Array, default: () => [] },
  botModes: { type: Array, default: () => [] },
  palette: { type: Array, default: () => [] },
});

const EVENT_LABELS = {
  created: 'أُنشئت الجلسة', api_key: 'مفتاح جديد', webhook: 'سُجّل الـ webhook', ready: 'اتصل', status: 'تغيّرت الحالة',
  authenticated: 'رُبط بالهاتف', disconnected: 'انقطع', reconnect_loop: 'يعيد الاتصال باستمرار', restriction: '⛔ علامة تقييد من واتساب',
  restart: 'إعادة تشغيل', probe_failed: 'فشل الفحص', probe_ok: 'نجح الفحص', logout: 'فُك الربط', destroyed: 'حُذفت الجلسة',
  paused: 'إيقاف مؤقت', resumed: 'رُفع الإيقاف', breaker: 'قاطع الإخفاقات', backfill: 'استرجاع ما فات', history_import: 'استيراد سجل الهاتف',
};

const HISTORY_LABELS = { running: 'جارٍ الاستيراد', stopped: 'موقوف', done: 'اكتمل', failed: 'توقف بخطأ' };
function historyLabel(h) {
  if (!h?.status) return 'لم يُستورد بعد';
  if (h.status === 'running' && h.starts_at && new Date(h.starts_at) > new Date()) return `ينتظر اكتمال مزامنة واتساب للهاتف، يبدأ ${fmt(h.starts_at)}`;
  if (h.status === 'running' && h.phase === 'list') return 'يقرأ قائمة المحادثات…';
  return HISTORY_LABELS[h.status] || h.status;
}
function importAction(c) {
  const st = c.history?.status;
  if (st === 'stopped' || st === 'failed') return { cmd: 'import_start', label: '📥 استئناف الاستيراد', short: 'استئناف' };
  return {
    cmd: 'import_start', label: '📥 استيراد سجل الهاتف', short: st === 'done' ? 'استيراد من جديد' : 'استيراد السجل',
    ask: 'تُقرأ محادثات الرقم من الهاتف وتُحفظ تاريخاً صامتاً: لا يرد عليها البوت، ولا تصل منها إشعارات. قد يستغرق ذلك ساعات لرقم كبير، والإرسال والاستقبال يعملان أثناءه.',
  };
}

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const { confirm } = useConfirm();
const list = ref([...props.channels]);
const linking = ref(null);
const linkDialog = ref(null);
const showForm = ref(false);
const editing = ref(null);
const busy = ref(false);
const formError = ref('');
const notice = ref(null);
const eventsOf = ref(null);
const events = ref([]);
const form = ref({});

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('ar-JO', { dateStyle: 'short', timeStyle: 'short' }) : '');
function ago(iso) {
  const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  return m < 1 ? 'الآن' : m < 60 ? `قبل ${m} د` : `قبل ${Math.round(m / 60)} س`;
}

async function refresh() {
  try {
    const { data } = await axios.get('/api/whatsapp/channels', api);
    list.value = data.channels;
    if (linking.value) linking.value = list.value.find((c) => c.id === linking.value.id) || linking.value;
  } catch { /* يبقى آخر عرض */ }
}

// تقدّم الاستيراد لا يطلق أحداث قناة: تحديث كل 10 ثوانٍ ما دام استيراد جارياً
let poller = null;
onMounted(() => {
  poller = setInterval(() => {
    if (list.value.some((c) => c.history?.status === 'running')) refresh();
  }, 10000);
});
onUnmounted(() => clearInterval(poller));

useChannelEvents((e) => {
  refresh();
  if (e.qr && linking.value?.id === e.channelId) linkDialog.value?.poll();
});

function openForm(c = null) {
  editing.value = c;
  formError.value = '';
  form.value = c
    ? { name: c.name, roles: [...c.roles], color: c.color, bot_mode: c.bot_mode, is_default_for: [...c.is_default_for],
        users: c.users.map((u) => u.id), primary: c.users.find((u) => u.primary)?.id || null }
    : { name: '', roles: ['sales'], color: props.palette[list.value.length % props.palette.length] || '#b8800f' };
  showForm.value = true;
}

async function save() {
  busy.value = true;
  formError.value = '';
  try {
    if (editing.value) {
      const f = form.value;
      await axios.patch(`/api/whatsapp/channels/${editing.value.id}`, {
        name: f.name, roles: f.roles, color: f.color, bot_mode: f.bot_mode, is_default_for: f.is_default_for,
        users: f.users.map((id) => ({ id, primary: id === f.primary })),
      }, api);
      showForm.value = false;
    } else {
      const { data } = await axios.post('/api/whatsapp/channels', form.value, api);
      showForm.value = false;
      await refresh();
      linking.value = list.value.find((c) => c.id === data.channel.id) || data.channel;
    }
    await refresh();
  } catch (e) {
    formError.value = errorText(e, 'تعذّر الحفظ');
    await refresh();
  } finally {
    busy.value = false;
  }
}

function actionsFor(c) {
  const out = [];
  if (!c.provisioned) out.push({ cmd: 'repair', label: '🛠 إعادة الإعداد' });
  out.push({ cmd: 'restart', label: '🔄 إعادة تشغيل الجلسة' });
  if (c.paused_until) out.push({ cmd: 'resume', label: '▶️ رفع الإيقاف المؤقت' });
  else out.push({ cmd: 'pause', label: '⏸ إيقاف مؤقت ساعة', body: { minutes: 60 } });
  if (c.breaker_until) out.push({ cmd: 'reset_breaker', label: '🧯 رفع قاطع الإخفاقات' });
  if (c.provisioned) out.push({ cmd: 'repair', label: '🛠 مفتاح وwebhook جديدان' });
  if (c.linked) out.push({ cmd: 'logout', label: '🔌 فك الربط من الهاتف', danger: true, ask: 'فك ربط هذا الرقم؟ يتوقف الإرسال والاستقبال عليه حتى يُمسح QR جديد.' });
  out.push({ cmd: 'destroy', label: '🗑 حذف الرقم', danger: true, ask: 'حذف هذا الرقم من البوابة؟ تبقى محادثاته في نُسك للقراءة، ولا يُرسل منه شيء.' });
  return out;
}

async function run(c, a) {
  if (a.ask && !(await confirm({ title: a.label, message: a.ask, confirmLabel: 'نعم', danger: !!a.danger }))) return;
  notice.value = null;
  try {
    if (a.cmd === 'destroy') await axios.delete(`/api/whatsapp/channels/${c.id}`, api);
    else await axios.post(`/api/whatsapp/channels/${c.id}/${a.cmd}`, a.body || {}, api);
    notice.value = { ok: true, text: `تم: ${a.label.replace(/^\S+\s/, '')}` };
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر تنفيذ الأمر') };
  }
  refresh();
}

async function showEvents(c) {
  eventsOf.value = c;
  events.value = [];
  try {
    const { data } = await axios.get(`/api/whatsapp/channels/${c.id}/events`, api);
    events.value = data.events;
  } catch { /* فارغ */ }
}

function onLinked(ch) {
  refresh();
  notice.value = { ok: true, text: `رُبط «${ch?.name || ''}» بنجاح` };
}
</script>
