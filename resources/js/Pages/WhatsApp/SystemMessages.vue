<template>
  <AppLayout>
    <template #header>رسائل النظام</template>

    <div class="space-y-6 pb-4">
      <div class="p-4 rounded-xl border text-sm leading-relaxed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 text-gray-600 dark:text-gray-300 space-y-1.5">
        <p>رسائل يرسلها النظام وحده عند حدث أو موعد: الفاتورة والسند والتذكيرات والاطمئنان على المسافر. تخرج عبر طابور الإرسال وبحدود كل رقم.</p>
        <p>كل نوع يبدأ في <b>مرحلة الاختبار</b>: يصل أرقام الاختبار وحدها. بعد تجربة واحدة ناجحة اضغط «إطلاق» فيصل العملاء.</p>
        <p>النص يُكتب لحظة الإرسال من البيانات الحالية، فإن سُدّد المتبقي أو تغيّر موعد السفر أُلغي التذكير القديم وحده.</p>
      </div>

      <!-- وضع الاختبار وأرقامه -->
      <section class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-4 space-y-3 border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <h3 class="font-bold text-gray-800 dark:text-gray-100">أرقام الاختبار</h3>
          <label class="flex items-center gap-2 text-sm font-bold" :class="testMode ? 'text-amber-700 dark:text-amber-400' : ''">
            <input type="checkbox" :checked="testMode" @change="saveSettings({ test_mode: $event.target.checked })" />
            وضع الاختبار العام (كل الأنواع لأرقام الاختبار وحدها)
          </label>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
          <span v-for="(p, i) in recipients" :key="p" class="px-2 py-1 rounded-lg bg-teal-50 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300 text-sm font-mono" dir="ltr">
            +{{ p }} <button type="button" class="ms-1 text-red-600" :aria-label="'حذف ' + p" @click="removeRecipient(i)">✕</button>
          </span>
          <form class="flex gap-2" @submit.prevent="addRecipient">
            <input v-model="newRecipient" dir="ltr" inputmode="tel" placeholder="9627XXXXXXXX" aria-label="رقم اختبار" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm font-mono w-40" />
            <button type="submit" class="px-3 py-1.5 rounded-lg text-sm font-bold bg-gold-100 text-gold-800 dark:bg-gold-900/30 dark:text-gold-200">+ إضافة</button>
          </form>
        </div>
      </section>

      <p v-if="notice" role="status" class="p-3 rounded-xl text-sm" :class="notice.ok ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-300' : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300'">{{ notice.text }}</p>

      <section v-for="(label, g) in groups" :key="g" class="space-y-2">
        <h3 class="text-sm font-bold text-gray-500 dark:text-gray-400">{{ label }}</h3>
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm divide-y divide-gray-100 dark:divide-gray-800">
          <div v-for="t in list.filter((x) => x.group === g)" :key="t.key" class="p-4 flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-[220px]">
              <p class="font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2 flex-wrap">
                {{ t.label }}
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold" :class="t.stage === 'live' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'">{{ t.stage === 'live' ? 'مُطلق للعملاء' : 'في الاختبار' }}</span>
                <span v-if="!t.enabled || t.mode === 'off'" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">مطفأ</span>
                <span v-else-if="t.mode === 'approve'" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-300">بموافقة</span>
                <span v-if="t.attachment" class="text-xs">📎 PDF</span>
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ t.description }} · {{ timingText(t) }}</p>
              <p class="text-xs text-gray-400 mt-0.5">30 يوماً: {{ t.stats.sent }} أُرسلت · {{ t.stats.open }} قادمة · {{ t.stats.failed }} لم تُرسل · {{ t.stats.tests }} تجربة</p>
            </div>
            <div class="flex items-center gap-1.5 flex-wrap">
              <button type="button" @click="open(t)" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ القالب والتوقيت</button>
              <button type="button" @click="openTest(t)" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-teal-50 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300">🧪 تجربة</button>
              <button v-if="t.stage !== 'live'" type="button" @click="setStage(t, 'live')" :disabled="!t.tests_count" :title="t.tests_count ? '' : 'جرّبه أولاً'"
                      class="px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-green-600 hover:bg-green-700 disabled:opacity-40">🚀 إطلاق</button>
              <button v-else type="button" @click="setStage(t, 'test')" class="px-3 py-1.5 rounded-lg text-xs font-bold text-amber-700 dark:text-amber-400 hover:bg-amber-50">↩ للاختبار</button>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- المحرر -->
    <AppModal :open="!!editing" :title="editing ? editing.label : ''" size="xl" :dirty="dirty" @close="editing = null">
      <form v-if="editing" id="type-form" class="grid lg:grid-cols-2 gap-5 text-sm" @submit.prevent="save">
        <div class="space-y-3">
          <label class="flex items-center gap-2 font-bold"><input type="checkbox" v-model="form.enabled" /> مفعّل</label>
          <fieldset><legend class="font-bold mb-1">الوضع</legend>
            <div class="flex gap-3">
              <label v-for="(l, m) in MODES" :key="m" class="flex items-center gap-1.5"><input type="radio" :value="m" v-model="form.mode" /> {{ l }}</label>
            </div>
          </fieldset>
          <label class="block"><span class="font-bold">الرقم المرسل</span>
            <select v-model="form.channel_id" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800">
              <option :value="null">رقم محادثة العميل، وإلا الرقم الافتراضي للمجموعة</option>
              <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </label>
          <div v-if="editing.timing.anchor !== 'manual'" class="grid grid-cols-2 gap-3">
            <label v-if="'at' in editing.timing" class="block"><span class="font-bold">الساعة (عمّان)</span>
              <input v-model="form.at" type="time" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" dir="ltr" />
            </label>
            <label v-if="'offsets' in editing.timing" class="block"><span class="font-bold">الأيام من {{ ANCHORS[editing.timing.anchor] }}</span>
              <input v-model="form.offsets" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" dir="ltr" placeholder="-3, 0" />
            </label>
            <label v-if="'delay_minutes' in editing.timing" class="block"><span class="font-bold">بعد الحدث (دقائق)</span>
              <input v-model.number="form.delay_minutes" type="number" min="0" max="1440" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" />
            </label>
          </div>
          <label class="block"><span class="font-bold">القالب</span>
            <textarea ref="tpl" v-model="form.template" rows="9" maxlength="3500" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 leading-relaxed"></textarea>
          </label>
          <div class="flex flex-wrap gap-1.5">
            <button v-for="v in editing.vars" :key="v" type="button" @click="insert(v)" class="px-2 py-0.5 rounded-full text-xs font-mono bg-gray-100 dark:bg-gray-800" dir="ltr">{{ '{' + v + '}' }}</button>
            <button type="button" class="text-xs text-gray-500 underline ms-2" @click="form.template = editing.default_template">القالب الافتراضي</button>
          </div>
        </div>
        <div class="space-y-3">
          <div class="flex items-end gap-2">
            <label v-if="sampleList.length" class="block flex-1"><span class="font-bold">معاينة على</span>
              <select v-model="sourceId" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                <option v-for="s in sampleList" :key="s.id" :value="s.id">{{ s.label }}</option>
              </select>
            </label>
            <button type="button" @click="runPreview" class="px-3 py-2 rounded-lg font-bold bg-gray-100 dark:bg-gray-800">👁 معاينة</button>
          </div>
          <div class="rounded-2xl p-3 min-h-[180px] bg-[#e5ddd5] dark:bg-gray-800">
            <div v-if="preview" class="max-w-[90%] ms-auto rounded-xl rounded-tl-none bg-[#dcf8c6] dark:bg-green-900/40 p-3 whitespace-pre-wrap leading-relaxed">
              <p v-if="editing.attachment" class="text-xs text-gray-600 mb-1">📎 ملف PDF مرفق</p>{{ preview.text }}
            </div>
            <p v-if="preview && !preview.ok" class="mt-2 text-xs text-red-700">⚠️ {{ preview.reason || ('متغيرات ناقصة: ' + (preview.missing || []).join('، ')) }}</p>
            <p v-if="!preview" class="text-xs text-gray-500 text-center pt-16">اضغط «معاينة»</p>
          </div>
        </div>
      </form>
      <template #footer>
        <button type="button" @click="editing = null" class="px-4 py-2 rounded-xl font-bold text-gray-600 dark:text-gray-300">إلغاء</button>
        <button type="submit" form="type-form" class="px-4 py-2 rounded-xl font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400">حفظ</button>
      </template>
    </AppModal>

    <!-- التجربة -->
    <AppModal :open="!!testing" :title="testing ? 'تجربة: ' + testing.label : ''" size="md" @close="testing = null">
      <div v-if="testing" class="space-y-3 text-sm">
        <p>تُرسل نسخة الآن إلى أرقام الاختبار ({{ recipients.length }}) بسطر أول «🧪 [اختبار]»، ولا يصل العميل شيء.</p>
        <label v-if="samplesFor(testing).length" class="block"><span class="font-bold">على</span>
          <select v-model="testSource" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800">
            <option v-for="s in samplesFor(testing)" :key="s.id" :value="s.id">{{ s.label }}</option>
          </select>
        </label>
        <p v-if="!recipients.length" class="text-red-700">أضف رقم اختبار أولاً.</p>
      </div>
      <template #footer>
        <button type="button" @click="testing = null" class="px-4 py-2 rounded-xl font-bold text-gray-600">إلغاء</button>
        <button type="button" @click="runTest" :disabled="!recipients.length" class="px-4 py-2 rounded-xl font-bold text-white bg-teal-600 disabled:opacity-40">إرسال التجربة</button>
      </template>
    </AppModal>
  </AppLayout>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import AppModal from '@/Components/AppModal.vue';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  types: { type: Array, default: () => [] },
  groups: { type: Object, default: () => ({}) },
  channels: { type: Array, default: () => [] },
  testMode: Boolean,
  testRecipients: { type: Array, default: () => [] },
  samples: { type: Object, default: () => ({}) },
});

const MODES = { auto: 'تلقائي', approve: 'بموافقة موظف', off: 'مطفأ' };
const ANCHORS = { trip_date: 'تاريخ السفر', due_date: 'تاريخ الاستحقاق', return_date: 'تاريخ العودة', priced_at: 'إرسال السعر' };
const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const list = ref([...props.types]);
const testMode = ref(props.testMode);
const recipients = ref([...props.testRecipients]);
const newRecipient = ref('');
const notice = ref(null);
const editing = ref(null);
const form = ref({});
const baseline = ref('');
const preview = ref(null);
const sourceId = ref(null);
const tpl = ref(null);
const testing = ref(null);
const testSource = ref(null);

const dirty = computed(() => !!editing.value && JSON.stringify(form.value) !== baseline.value);
const samplesFor = (t) => props.samples[t.source] || [];
const sampleList = computed(() => (editing.value ? samplesFor(editing.value) : []));

function timingText(t) {
  const at = t.timing.at ? ` الساعة ${t.timing.at}` : '';
  if (t.timing.anchor === 'now') return `بعد الحدث بـ ${t.timing.delay_minutes || 0} دقيقة`;
  if (t.timing.anchor === 'daily') return `يومياً${at}`;
  if (t.timing.anchor === 'manual') return 'في الموعد الذي يختاره الموظف';
  const days = (t.timing.offsets || []).map((d) => (d === 0 ? 'في يومه' : d < 0 ? `قبل ${-d} يوم` : `بعد ${d} يوم`)).join(' و');
  return `${days} من ${ANCHORS[t.timing.anchor] || t.timing.anchor}${at}`;
}

function replaceType(t) {
  const i = list.value.findIndex((x) => x.key === t.key);
  if (i >= 0) list.value[i] = t;
}

function open(t) {
  editing.value = t;
  preview.value = null;
  sourceId.value = samplesFor(t)[0]?.id ?? null;
  form.value = { enabled: t.enabled, mode: t.mode, channel_id: t.channel_id, template: t.template,
    at: t.timing.at || '', offsets: (t.timing.offsets || []).join(', '), delay_minutes: t.timing.delay_minutes ?? 0 };
  baseline.value = JSON.stringify(form.value);
}

function insert(v) {
  const el = tpl.value;
  const token = `{${v}}`;
  if (!el) { form.value.template += token; return; }
  const [s, e] = [el.selectionStart, el.selectionEnd];
  form.value.template = form.value.template.slice(0, s) + token + form.value.template.slice(e);
  nextTick(() => { el.focus(); el.selectionStart = el.selectionEnd = s + token.length; });
}

async function runPreview() {
  try {
    const { data } = await axios.post(`/api/whatsapp/system-messages/${editing.value.key}/preview`, { template: form.value.template, source_id: sourceId.value }, api);
    preview.value = data;
  } catch (e) {
    preview.value = { ok: false, text: '', reason: errorText(e, 'تعذّرت المعاينة') };
  }
}

async function save() {
  const t = editing.value;
  const timing = {};
  if ('at' in t.timing && form.value.at) timing.at = form.value.at;
  if ('offsets' in t.timing) timing.offsets = String(form.value.offsets).split(/[,،\s]+/).filter((x) => x !== '').map(Number);
  if ('delay_minutes' in t.timing) timing.delay_minutes = Number(form.value.delay_minutes) || 0;
  try {
    const { data } = await axios.patch(`/api/whatsapp/system-messages/${t.key}`, {
      enabled: form.value.enabled, mode: form.value.mode, channel_id: form.value.channel_id,
      template: form.value.template === t.default_template ? null : form.value.template, timing,
    }, api);
    replaceType(data.type);
    editing.value = null;
    notice.value = { ok: true, text: `حُفظ «${t.label}»` };
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر الحفظ') };
  }
}

function openTest(t) {
  testing.value = t;
  testSource.value = samplesFor(t)[0]?.id ?? null;
}

async function runTest() {
  const t = testing.value;
  try {
    const { data } = await axios.post(`/api/whatsapp/system-messages/${t.key}/test`, { source_id: testSource.value }, api);
    replaceType(data.type);
    notice.value = { ok: true, text: `أُضيفت ${data.queued} رسالة تجريبية إلى الطابور، وتخرج خلال دقيقة.` };
    testing.value = null;
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّرت التجربة') };
  }
}

async function setStage(t, stage) {
  try {
    const { data } = await axios.post(`/api/whatsapp/system-messages/${t.key}/stage`, { stage }, api);
    replaceType(data.type);
    notice.value = { ok: true, text: stage === 'live' ? `أُطلق «${t.label}» للعملاء` : `عاد «${t.label}» للاختبار` };
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر تغيير المرحلة') };
  }
}

async function saveSettings(body) {
  try {
    const { data } = await axios.patch('/api/whatsapp/system-messages-settings', body, api);
    testMode.value = data.testMode;
    recipients.value = data.testRecipients;
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر الحفظ') };
  }
}

function addRecipient() {
  if (!newRecipient.value.trim()) return;
  saveSettings({ test_recipients: [...recipients.value, newRecipient.value.trim()] });
  newRecipient.value = '';
}

function removeRecipient(i) {
  saveSettings({ test_recipients: recipients.value.filter((_, j) => j !== i) });
}
</script>
