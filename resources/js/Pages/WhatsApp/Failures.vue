<template>
  <AppLayout>
    <template #header>الإخفاقات والطابور</template>

    <!-- مركز الإخفاقات (E24): قراءة من المصادر القائمة، وثلاثة أفعال محروسة بتأكيد ومدقَّقة في الخادم -->
    <div class="space-y-4 max-w-5xl">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-gray-600 dark:text-gray-300 min-w-0">
          ما لم يصل وما فشل في الخلفية خلال 7 أيام، ضمن نطاقك. أرقام مقنَّعة ومقتطف قصير فقط.
        </p>
        <div class="flex items-center gap-2 shrink-0">
          <span v-if="data.generated_at" class="text-xs text-gray-400" dir="ltr">{{ data.generated_at }}</span>
          <button type="button" data-test="refresh" :disabled="loading" @click="refresh"
                  class="min-h-11 px-4 py-2 rounded-xl text-sm font-bold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50">
            {{ loading ? '⏳ جارٍ التحديث…' : '🔄 تحديث' }}
          </button>
        </div>
      </div>

      <p v-if="error" role="alert" class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 px-3 py-2 text-sm text-red-700 dark:text-red-300">❌ {{ error }}</p>
      <p v-if="notice" role="status" class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 px-3 py-2 text-sm text-green-700 dark:text-green-300">✅ {{ notice }}</p>

      <div class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-700" role="tablist" aria-label="أقسام الإخفاقات">
        <button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" :data-test="`tab-${t.key}`"
                class="min-h-11 px-3 sm:px-4 py-2 -mb-px border-b-2 text-sm font-bold whitespace-nowrap"
                :class="tab === t.key ? 'border-gold-500 text-gold-700 dark:text-gold-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                @click="select(t.key)">
          {{ t.label }}<span v-if="t.count !== null" class="ms-1 text-xs">({{ t.count }})</span>
        </button>
      </div>

      <!-- ① رسائل لم تصل -->
      <section v-show="tab === 'messages'" role="tabpanel" aria-label="رسائل لم تصل" class="space-y-2">
        <ul v-if="messages.length" class="space-y-2" data-test="messages">
          <li v-for="m in messages" :key="m.id" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 text-sm space-y-1.5">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <p class="font-bold text-gray-800 dark:text-gray-100 min-w-0 break-words">
                <bdi dir="auto" class="[unicode-bidi:plaintext]">{{ m.conversation }}</bdi>
                <span class="ms-1 text-xs font-normal text-gray-500 dark:text-gray-400">{{ SOURCES[m.source] || m.source }}</span>
              </p>
              <span class="px-2 py-0.5 rounded-full text-[11px] font-bold shrink-0"
                    :class="m.maybe_delivered ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'">
                {{ m.maybe_delivered ? '❔ قد تكون وصلت' : '✕ لم تصل' }}
              </span>
            </div>
            <p v-if="m.snippet" class="text-xs text-gray-600 dark:text-gray-300 break-words">
              <bdi dir="auto" class="[unicode-bidi:plaintext]">«{{ m.snippet }}»</bdi>
            </p>
            <p v-else class="text-xs text-gray-500 dark:text-gray-400">{{ TYPES[m.msg_type] || m.msg_type }}</p>
            <p class="text-xs text-gray-700 dark:text-gray-200">
              <span class="font-bold">{{ CATEGORIES[m.category] || m.category }}</span>
              <template v-if="m.staff_message"> — {{ m.staff_message }}</template>
              <template v-if="m.action && !m.maybe_delivered"> <span class="text-gray-500 dark:text-gray-400">({{ m.action }})</span></template>
            </p>
            <p class="text-[11px] text-gray-500 dark:text-gray-400">
              <bdi dir="ltr">{{ m.at }}</bdi>
              <template v-if="m.meta_code"> · رمز ميتا <bdi dir="ltr" class="font-mono">{{ m.meta_code }}</bdi></template>
              <template v-else-if="m.error_code"> · <bdi dir="ltr" class="font-mono">{{ m.error_code }}</bdi></template>
              · محاولات {{ m.attempts }}<template v-if="m.redrives"> · أُعيدت {{ m.redrives }}</template>
              <template v-if="!m.window_open"> · النافذة مغلقة</template>
            </p>
            <div class="flex flex-wrap gap-1.5 pt-1">
              <Link :href="`/whatsapp/inbox?conv=${m.conversation_id}`"
                    class="min-h-11 lg:min-h-0 inline-flex items-center px-2.5 py-1 rounded-lg text-xs border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200">💬 افتح المحادثة</Link>
              <button v-if="m.can_redrive" type="button" :disabled="busy" :data-test="`redrive-${m.id}`" @click="redrive(m)"
                      class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-gold-400 text-gold-700 dark:text-gold-400 disabled:opacity-50">↻ إعادة الإرسال</button>
            </div>
          </li>
        </ul>
        <p v-else class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 px-3 py-10 text-center text-sm text-gray-400">لا رسائل فاشلة أو غير مؤكدة خلال 7 أيام ✅</p>
      </section>

      <!-- ② مهام خلفية فاشلة -->
      <section v-show="tab === 'jobs'" role="tabpanel" aria-label="مهام خلفية فاشلة" class="space-y-2">
        <ul v-if="jobs.length" class="space-y-2" data-test="jobs">
          <li v-for="j in jobs" :key="j.uuid" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 text-sm space-y-1.5">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <p class="font-bold text-gray-800 dark:text-gray-100 font-mono min-w-0 break-all" dir="ltr">{{ j.job }}</p>
              <span class="text-[11px] text-gray-500 dark:text-gray-400 shrink-0"><bdi dir="ltr">{{ j.at }}</bdi> · <bdi dir="ltr">{{ j.queue }}</bdi></span>
            </div>
            <p class="text-xs text-gray-600 dark:text-gray-300 font-mono break-all" dir="ltr">{{ j.error }}</p>
            <div class="flex flex-wrap gap-1.5 pt-1">
              <Link v-if="j.conversation_id" :href="`/whatsapp/inbox?conv=${j.conversation_id}`"
                    class="min-h-11 lg:min-h-0 inline-flex items-center px-2.5 py-1 rounded-lg text-xs border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200">💬 افتح المحادثة</Link>
              <button v-if="j.retryable" type="button" :disabled="busy" :data-test="`retry-${j.uuid}`" @click="retryJob(j)"
                      class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-gold-400 text-gold-700 dark:text-gold-400 disabled:opacity-50">↻ إعادة المعالجة</button>
              <span v-else-if="j.reply_job" class="text-[11px] text-gray-500 dark:text-gray-400">ردود البوت لا تُعاد من هنا: افتح المحادثة (وزر «إعادة معالجة» للرد العالق).</span>
            </div>
          </li>
        </ul>
        <p v-else class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 px-3 py-10 text-center text-sm text-gray-400">لا مهام فاشلة خلال 24 ساعة ✅</p>
      </section>

      <!-- ③ ويبهوك فاشل -->
      <section v-show="tab === 'webhooks'" role="tabpanel" aria-label="ويبهوك فاشل" class="space-y-2">
        <p v-if="rejectedTotal" class="text-xs text-gray-600 dark:text-gray-300" data-test="rejected">
          مرفوض بالسياج خلال 7 أيام: <template v-for="(n, reason, i) in webhooks.rejected" :key="reason"><template v-if="i"> · </template><bdi dir="ltr" class="font-mono">{{ reason }}</bdi> {{ n }}</template>
        </p>
        <ul v-if="webhooks.rows.length" class="space-y-2" data-test="webhooks">
          <li v-for="e in webhooks.rows" :key="e.id" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 text-sm space-y-1.5">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <p class="font-bold text-gray-800 dark:text-gray-100">#{{ e.id }} · {{ HOOK_STATUS[e.status] || e.status }}</p>
              <span class="text-[11px] text-gray-500 dark:text-gray-400 shrink-0"><bdi dir="ltr">{{ e.at }}</bdi> · محاولات {{ e.attempts }}</span>
            </div>
            <p class="text-xs text-gray-600 dark:text-gray-300">
              <bdi v-if="e.fields" dir="ltr" class="font-mono">{{ e.fields }}</bdi>
              <template v-if="e.error"> · <bdi dir="ltr" class="font-mono">{{ e.error }}</bdi></template>
              · البصمة <bdi dir="ltr" class="font-mono">{{ e.sha }}</bdi>
              <template v-if="e.status === 'failed' && e.next_attempt_at"> · المحاولة التالية <bdi dir="ltr">{{ e.next_attempt_at }}</bdi></template>
            </p>
            <div v-if="e.can_replay" class="pt-1">
              <button type="button" :disabled="busy" :data-test="`replay-${e.id}`" @click="replay(e)"
                      class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-gold-400 text-gold-700 dark:text-gold-400 disabled:opacity-50">↻ إعادة المعالجة</button>
            </div>
          </li>
        </ul>
        <p v-else class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 px-3 py-10 text-center text-sm text-gray-400">لا إشعارات فاشلة خلال 7 أيام ✅</p>
      </section>

      <!-- ④ الطابور الآن -->
      <section v-show="tab === 'queue'" role="tabpanel" aria-label="الطابور الآن" class="space-y-3" data-test="queue">
        <p v-if="!snap" class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 px-3 py-10 text-center text-sm text-gray-400">
          لا لقطة بعد: يأخذها whatsapp:ops-check كل 5 دقائق. إن طال غيابها فالمجدول متوقف (راجع صحة النظام).
        </p>
        <template v-else>
          <p class="text-xs text-gray-500 dark:text-gray-400">
            عمر آخر لقطة {{ data.queue.age_minutes }} د<template v-if="snap.scheduler_beat_age_s !== null"> · نبضة المجدول قبل {{ snap.scheduler_beat_age_s }} ث</template>
            <template v-if="snap.channel_health"> · القناة <bdi dir="ltr" class="font-mono">{{ snap.channel_health }}</bdi></template>
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div v-for="(q, name) in snap.queue" :key="name" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 text-sm">
              <p class="font-bold text-gray-800 dark:text-gray-100 font-mono" dir="ltr">{{ name }}</p>
              <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">
                تنتظر {{ q.jobs }} · أقدمها {{ q.oldest_age_s === null ? '—' : q.oldest_age_s + ' ث' }}
                <template v-if="q.reserved_stuck"> · <span class="text-red-600 dark:text-red-400 font-bold">عالقة {{ q.reserved_stuck }}</span></template>
              </p>
            </div>
          </div>
          <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div v-for="tile in tiles" :key="tile.label" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3">
              <dt class="text-xs text-gray-500 dark:text-gray-400">{{ tile.label }}</dt>
              <dd class="text-lg font-bold tnum mt-1" :class="tile.warn ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-gray-100'" dir="ltr">{{ tile.value }}</dd>
            </div>
          </dl>
          <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 text-sm space-y-2">
            <p class="font-bold text-gray-800 dark:text-gray-100">📤 صادر اليوم (يوم عمّان)</p>
            <p class="text-xs text-gray-600 dark:text-gray-300">
              <template v-for="(n, source, i) in snap.outbound_today?.by_source || {}" :key="source"><template v-if="i"> · </template>{{ SOURCES[source] || source }} {{ n }}</template>
              <template v-if="snap.outbound_today?.test"> · تجريبي {{ snap.outbound_today.test }} (خارج الإجمالي)</template>
            </p>
            <p class="text-xs text-gray-600 dark:text-gray-300">الرسائل الآلية بنوعها مقابل ميزانيتها اليومية:</p>
            <ul class="flex flex-wrap gap-1.5" data-test="automated">
              <li v-for="a in data.queue.automated" :key="a.kind" class="px-2 py-0.5 rounded-lg text-[11px] border"
                  :class="a.over ? 'border-red-300 text-red-700 dark:border-red-700 dark:text-red-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300'">
                {{ KINDS[a.kind] || a.kind }} {{ a.count }}<template v-if="a.budget !== null">/{{ a.budget }}</template>
              </li>
            </ul>
            <p class="text-xs text-gray-600 dark:text-gray-300">
              رسائل خدمة محتسبة هذا الشهر: {{ snap.service_billable_month ?? 0 }} من {{ data.queue.free_tier }} مجانية (تقديري).
            </p>
          </div>
        </template>
      </section>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import { useConfirm } from '@/composables/useConfirm';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  title: String,
  initial: { type: Object, required: true },
});

const SOURCES = { bot: 'البوت', staff: 'موظف', system: 'النظام', rule: 'قاعدة حتمية', test: 'تجريبي', proactive: 'مبادر', template: 'قالب' };
const CATEGORIES = { transient: 'عابر', throttle: 'خنق من ميتا', window: 'النافذة', auth: 'اعتماد', integrity: 'قيود الحساب', quality: 'الجودة', caller: 'المستلم أو الرسالة', unknown: 'غير معروف' };
const TYPES = { image: '🖼️ صورة', document: '📄 مستند', video: '🎬 فيديو', audio: '🎤 صوت', interactive: '🔘 رسالة بأزرار', template: 'قالب' };
const HOOK_STATUS = { failed: '⚠️ فاشل (يُعاد تلقائياً)', dead: '⛔ متوقف بعد محاولاته', malformed: '🧩 معطوب (لا يُعاد)' };
const KINDS = { reply: 'ردود البوت', rule: 'القواعد الحتمية', admin: 'المساعد الإداري', outage: 'تعطل المزوّد', reqcontact: 'طلب الرقم', consent: 'موافقة الملفات', optout: 'تأكيد الإيقاف', other: 'أخرى' };
const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };

const { confirm } = useConfirm();
const data = ref(props.initial);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');

const messages = computed(() => data.value.messages || []);
const jobs = computed(() => data.value.jobs || []);
const webhooks = computed(() => data.value.webhooks || { rows: [], rejected: {} });
const rejectedTotal = computed(() => Object.values(webhooks.value.rejected || {}).reduce((s, n) => s + Number(n || 0), 0));
const snap = computed(() => data.value.queue?.snapshot || null);

const tabs = computed(() => [
  { key: 'messages', label: 'رسائل لم تصل', count: messages.value.length },
  { key: 'jobs', label: 'مهام خلفية فاشلة', count: jobs.value.length },
  { key: 'webhooks', label: 'ويبهوك فاشل', count: webhooks.value.rows.length },
  { key: 'queue', label: 'الطابور الآن', count: null },
]);
const KEYS = ['messages', 'jobs', 'webhooks', 'queue'];
const tab = ref('messages');
// روابط التنبيهات تفتح التبويب المعني (#queue و#jobs…)
onMounted(() => {
  const hash = (window.location.hash || '').slice(1);
  if (KEYS.includes(hash)) tab.value = hash;
});
const select = (key) => {
  tab.value = key;
  if (window.history?.replaceState) window.history.replaceState(window.history.state, '', `#${key}`);
};

const sum = (o) => Object.values(o || {}).reduce((s, n) => s + Number(n || 0), 0);
const tiles = computed(() => {
  const s = snap.value || {};
  const failed = s.outbound_failed_1h || {};
  return [
    { label: 'مهام فاشلة (ساعة / يوم)', value: `${s.failed_jobs?.['1h'] ?? '—'} / ${s.failed_jobs?.['24h'] ?? '—'}`, warn: (s.failed_jobs?.['1h'] || 0) > 0 },
    { label: 'صادر فاشل في الساعة', value: sum(failed), warn: (failed.auth || 0) + (failed.integrity || 0) > 0 },
    { label: 'إرسال غير مؤكد (ساعة)', value: s.unknown_sends_1h ?? '—', warn: (s.unknown_sends_1h || 0) > 0 },
    { label: 'ويبهوك فاشل (24 ساعة)', value: s.webhook_failed_24h ?? '—', warn: (s.webhook_failed_24h || 0) > 0 },
    { label: 'ينتظرون موظفاً', value: s.waiting_human ?? '—', warn: (s.waiting_human || 0) >= 5 },
    { label: 'زمن البوت p90 (ث)', value: s.bot_latency_p90_ms_1h == null ? '—' : (s.bot_latency_p90_ms_1h / 1000).toFixed(1), warn: (s.bot_latency_p90_ms_1h || 0) > 60000 },
    { label: 'صادر اليوم', value: s.outbound_today?.total ?? '—', warn: false },
    { label: 'خدمة محتسبة (الشهر)', value: s.service_billable_month ?? '—', warn: false },
  ];
});

async function refresh() {
  loading.value = true;
  error.value = '';
  try {
    const { data: fresh } = await axios.get('/api/whatsapp/failures', api);
    data.value = fresh;
  } catch (e) {
    error.value = errorText(e, 'تعذّر التحديث');
  } finally {
    loading.value = false;
  }
}

/** نداء بخطأ عربي واحد؛ يعيد البيانات أو null */
async function call(url, payload, done) {
  busy.value = true;
  error.value = '';
  notice.value = '';
  try {
    const { data: res } = await axios.post(url, payload, api);
    notice.value = done;
    return res;
  } catch (e) {
    error.value = errorText(e, 'تعذّر تنفيذ الطلب');
    return null;
  } finally {
    busy.value = false;
  }
}

async function redrive(m) {
  if (!(await confirm({ title: 'إعادة الإرسال', message: 'ستُرسل الرسالة نفسها إلى العميل نفسه، على الصف نفسه وبمفتاحه نفسه. تأكد أنها لم تصل.', confirmLabel: '↻ إعادة الإرسال' }))) return;
  const res = await call(`/api/whatsapp/messages/${m.id}/redrive`, {}, 'أُعيد إرسال الرسالة.');
  if (res) await refresh();
}

async function retryJob(j) {
  if (!(await confirm({ title: 'إعادة المعالجة', message: `تعود المهمة ${j.job} إلى الطابور مرة واحدة.`, confirmLabel: '↻ إعادة' }))) return;
  if (await call(`/api/ops/failed-jobs/${j.uuid}/retry`, {}, 'أُعيدت المهمة إلى الطابور.')) await refresh();
}

async function replay(e) {
  if (!(await confirm({ title: 'إعادة معالجة الإشعار', message: 'يُعالج إشعار ميتا المحفوظ من جديد. بوابة الحداثة تمنع رداً آلياً على رسالة قديمة.', confirmLabel: '↻ إعادة' }))) return;
  // المتوقف: تأكيد ثانٍ بعد إصلاح السبب (--force)
  if (e.needs_force && !(await confirm({ title: 'إشعار متوقف بعد محاولاته', message: 'هل أصلحت سبب الفشل؟ الإعادة تتجاوز حد المحاولات.', confirmLabel: 'نعم، أعد', danger: true }))) return;
  if (await call(`/api/whatsapp/webhook-events/${e.id}/replay`, e.needs_force ? { confirm: true } : {}, 'أُعيدت معالجة الإشعار.')) await refresh();
}
</script>

<style scoped>
.tnum {
  font-variant-numeric: tabular-nums;
}
</style>
