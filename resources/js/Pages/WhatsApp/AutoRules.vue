<template>
  <AppLayout>
    <template #header>الردود الحتمية</template>

    <div class="space-y-6 pb-4">
      <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
      <div v-if="$page.props.errors?.toggle || $page.props.errors?.name" role="alert" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">
        ❌ {{ $page.props.errors.toggle || $page.props.errors.name }}
      </div>

      <!-- الحالة -->
      <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-4 sm:p-5 space-y-3">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="modeBadge.cls" data-test="mode-badge">{{ modeBadge.label }}</span>
          <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200" data-test="rule-count">
            {{ rules.length }} من {{ limits.rules }}
          </span>
          <a v-if="canSettings" href="/whatsapp/settings#flags" class="text-xs font-bold text-gold-700 dark:text-gold-400 hover:underline">لوحة الأعلام ←</a>
        </div>
        <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-300">
          نص ثابت يعتمده المالك لأسئلة متكررة جداً (الموقع، الدوام، الوثائق)، يُرسل بلا نموذج ويبقى يعمل حين يتعطل مزوّد الذكاء.
          يُقيَّم بعد كل حواجز البوت: لا يرد على محادثة بيد موظف، ولا مع طلب تسعير مفتوح، ولا على طلب موظف، ولا على رسالة أطول من حدها.
          <b>لا أسعار ولا بيانات دفع ولا رقم هاتف مكتوب</b>؛ البيانات الثابتة من متغيرات الشركة.
        </p>
        <div v-if="canSettings && flag.on" class="flex items-center gap-2 flex-wrap">
          <button type="button" @click="switchMode" :disabled="modeBusy" data-test="mode-switch"
                  class="px-3 py-1.5 rounded-lg text-xs font-bold border disabled:opacity-40"
                  :class="mode === 'live' ? 'border-amber-300 text-amber-800 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-900/20' : 'border-green-300 text-green-800 dark:text-green-300 hover:bg-green-50 dark:hover:bg-green-900/20'">
            {{ mode === 'live' ? '⏸️ العودة إلى الظل' : '▶️ التحويل إلى الإرسال الفعلي' }}
          </button>
          <span v-if="modeError" class="text-xs text-red-600 dark:text-red-400" role="alert">{{ modeError }}</span>
        </div>
      </div>

      <!-- جرّب نصاً -->
      <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-4 sm:p-5 space-y-3">
        <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">🧪 جرّب نصاً</h3>
        <form @submit.prevent="runTest" class="flex flex-col sm:flex-row gap-2">
          <label for="ar-test" class="sr-only">نص للتجربة</label>
          <input id="ar-test" v-model="testText" maxlength="500" dir="auto" placeholder="مثال: وين موقعكم؟"
                 class="w-full min-w-0 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
          <label for="ar-test-linked" class="sr-only">الربط بعميل</label>
          <select id="ar-test-linked" v-model="testLinked" class="sm:w-44 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm">
            <option value="no">عميل غير مربوط</option>
            <option value="yes">عميل مربوط</option>
          </select>
          <button type="submit" :disabled="testing || !testText.trim()" data-test="run-test"
                  class="shrink-0 px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-40">جرّب</button>
        </form>
        <div v-if="testResult" class="space-y-2 text-sm" data-test="test-result">
          <p class="text-xs text-gray-500 dark:text-gray-400">بعد التطبيع: <bdi dir="auto" class="font-bold">{{ testResult.normalized || '—' }}</bdi> · {{ testResult.words }} كلمة</p>
          <div v-if="testResult.match" class="rounded-lg border border-teal-200 dark:border-teal-800 bg-teal-50 dark:bg-teal-900/20 p-3 space-y-1">
            <p class="font-bold text-teal-800 dark:text-teal-200">⚡ تطابق: {{ testResult.match.name }}<span v-if="!testResult.match.is_active" class="font-normal"> (موقوفة)</span></p>
            <p dir="rtl" class="whitespace-pre-line [unicode-bidi:plaintext]">{{ testResult.match.reply_ar || '— لا رد الآن (متغير فارغ أو نص مرفوض)' }}</p>
            <p v-if="testResult.match.reply_en" dir="ltr" class="whitespace-pre-line text-gray-600 dark:text-gray-300">{{ testResult.match.reply_en }}</p>
          </div>
          <p v-else class="text-gray-600 dark:text-gray-300">لا قاعدة تطابق: يجيب النموذج.</p>
          <ul class="text-xs text-gray-500 dark:text-gray-400 space-y-0.5">
            <li v-for="r in testResult.results" :key="r.id">{{ r.name }}: {{ REASONS[r.reason] || r.reason }}</li>
          </ul>
        </div>
        <p v-if="testError" class="text-xs text-red-600 dark:text-red-400" role="alert">{{ testError }}</p>
      </div>

      <div class="flex justify-between items-center gap-3 flex-wrap">
        <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">القواعد</h3>
        <div class="flex gap-2 flex-wrap">
          <button type="button" @click="simulate" :disabled="simulating || !rules.length" data-test="simulate"
                  class="px-4 py-2 rounded-xl text-sm font-bold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40">
            {{ simulating ? '⏳ جارٍ المحاكاة…' : '📊 محاكاة على آخر 30 يوماً' }}
          </button>
          <button type="button" @click="openEditor()" :disabled="rules.length >= limits.rules"
                  class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-40">+ قاعدة جديدة</button>
        </div>
      </div>
      <p v-if="simulation" class="text-xs text-gray-500 dark:text-gray-400" data-test="simulation-total">شملت المحاكاة {{ simulation.total }} رسالة نصية من العملاء خلال {{ simulation.days }} يوماً.</p>
      <p v-if="simulateError" class="text-xs text-red-600 dark:text-red-400" role="alert">{{ simulateError }}</p>

      <div v-if="rules.length" class="space-y-3">
        <article v-for="(r, i) in rules" :key="r.id" class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-4 space-y-2 min-w-0"
                 :class="r.is_active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600'" :data-test="`rule-${r.id}`">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div class="min-w-0 flex items-center gap-2 flex-wrap">
              <h4 class="font-bold text-gray-800 dark:text-gray-100 break-words">{{ r.name }}</h4>
              <span class="px-2 py-0.5 rounded-full text-[11px] bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ MATCH_TYPES[r.match_type] || r.match_type }}</span>
              <span v-if="!r.validated" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">لم تُجرَّب بعد آخر تعديل</span>
              <span v-if="r.last_error" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-600 text-white" :title="r.last_error" data-test="last-error">⛔ تالفة</span>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <label class="relative inline-flex items-center cursor-pointer me-1" :title="!r.validated && !r.is_active ? 'جرّبها أولاً' : ''">
                <input type="checkbox" class="sr-only peer" :checked="r.is_active" :disabled="!r.validated && !r.is_active" @change="toggle(r)"
                       :aria-label="(r.is_active ? 'إيقاف ' : 'تفعيل ') + r.name" data-test="toggle"/>
                <span class="block w-10 h-6 rounded-full bg-gray-300 dark:bg-gray-600 peer-checked:bg-green-500 peer-disabled:opacity-40 transition-colors"></span>
                <span class="absolute top-0.5 start-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:-translate-x-4"></span>
              </label>
              <button type="button" @click="move(i, -1)" :disabled="i === 0" :aria-label="'تحريك ' + r.name + ' للأعلى'" class="min-w-9 min-h-9 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-30">▲</button>
              <button type="button" @click="move(i, 1)" :disabled="i === rules.length - 1" :aria-label="'تحريك ' + r.name + ' للأسفل'" class="min-w-9 min-h-9 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-30">▼</button>
              <button type="button" @click="openEditor(r)" class="px-2 py-1 rounded-lg text-xs font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ تعديل</button>
              <button type="button" @click="remove(r)" :aria-label="'حذف ' + r.name" title="حذف" class="min-w-9 min-h-9 rounded-lg text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">🗑️</button>
            </div>
          </div>
          <div class="flex flex-wrap gap-1.5">
            <span v-for="p in r.patterns" :key="p" dir="auto" class="px-2 py-0.5 rounded-full text-xs bg-teal-50 text-teal-800 dark:bg-teal-900/30 dark:text-teal-200 [unicode-bidi:plaintext]">{{ p }}</span>
          </div>
          <p v-if="conditionsText(r)" class="text-xs text-gray-500 dark:text-gray-400">{{ conditionsText(r) }}</p>
          <p dir="auto" class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line leading-relaxed [unicode-bidi:plaintext] break-words">{{ r.reply_text_ar }}</p>
          <p v-if="r.reply_text_en" dir="ltr" class="text-sm text-gray-500 dark:text-gray-400 whitespace-pre-line break-words">{{ r.reply_text_en }}</p>
          <ul v-if="r.guard_errors?.length" class="text-xs text-red-600 dark:text-red-400 list-disc ps-5" role="alert">
            <li v-for="e in r.guard_errors" :key="e">{{ e }}</li>
          </ul>
          <p class="text-[11px] text-gray-400">
            طابقت {{ r.hits }} مرة (ظل {{ r.shadow_hits }})<template v-if="r.last_hit_at"> · آخر مطابقة <bdi dir="ltr">{{ r.last_hit_at }}</bdi></template>
            · تهدئة {{ r.cooldown_minutes }} د · حتى {{ r.max_words }} كلمات<template v-if="r.tag_topic"> · وسم {{ topics[r.tag_topic] }}</template>
            <template v-if="r.updated_by"> · {{ r.updated_by }}</template>
          </p>
          <!-- نتيجة المحاكاة لهذه القاعدة -->
          <div v-if="sim(r.id)" class="rounded-lg bg-gray-50 dark:bg-gray-800/60 px-3 py-2 text-xs space-y-1" :data-test="`sim-${r.id}`">
            <p :class="sim(r.id).broad ? 'font-bold text-red-700 dark:text-red-400' : 'text-gray-700 dark:text-gray-200'">
              <template v-if="sim(r.id).invalid">⛔ تالفة: لم تُحاكَ.</template>
              <template v-else>طابقت {{ sim(r.id).matches }} رسالة ({{ percent(sim(r.id).share) }})<template v-if="sim(r.id).broad"> — ⚠️ واسعة جداً</template>
                · منها مع طلب تسعير مفتوح {{ sim(r.id).quote_open }} · تجاوزت حد الكلمات {{ sim(r.id).too_long }}</template>
            </p>
            <ul v-if="sim(r.id).snippets?.length" class="text-gray-500 dark:text-gray-400 space-y-0.5">
              <li v-for="(s, k) in sim(r.id).snippets" :key="k" dir="auto" class="[unicode-bidi:plaintext] break-words">«{{ s }}»</li>
            </ul>
          </div>
        </article>
      </div>
      <div v-else class="rounded-xl border-2 border-dashed p-10 text-center space-y-3 border-gray-300 dark:border-gray-600">
        <p class="text-sm text-gray-500 dark:text-gray-400">لا قواعد بعد. أضف القواعد الأولى بالنصوص التي اعتمدها المالك، ثم شغّل المحاكاة.</p>
        <button type="button" @click="openEditor()" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200">+ أضف أول قاعدة</button>
      </div>

      <ConversationalComponentsPanel v-if="automation" :initial="automation" :limits="limits"/>
    </div>

    <AutoRuleEditor ref="editor" :open="showEditor" :rule="editing" :errors="editorErrors" :processing="saving" :topics="topics" :variables="variables"
                    :msg-types="msgTypes" :limits="limits" @submit="save" @close="showEditor = false"/>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import AutoRuleEditor from '@/Components/WhatsApp/AutoRuleEditor.vue';
import ConversationalComponentsPanel from '@/Components/WhatsApp/ConversationalComponentsPanel.vue';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
  rules: { type: Array, default: () => [] },
  topics: { type: Object, default: () => ({}) },
  variables: { type: Object, default: () => ({}) },
  msgTypes: { type: Object, default: () => ({}) },
  limits: { type: Object, default: () => ({}) },
  mode: { type: String, default: 'shadow' },
  flag: { type: Object, default: () => ({ on: false }) },
  settingsVersion: { type: Number, default: 1 },
  canSettings: Boolean,
  canViewAll: Boolean,
  automation: { type: Object, default: null },
});

const { confirm } = useConfirm();

const MATCH_TYPES = { exact: 'مطابقة تامة', contains: 'تحوي كل القيم', any_of: 'تحوي أي قيمة' };
const REASONS = { match: 'تطابق', no_match: 'لا تطابق', too_long: 'الرسالة أطول من حد الكلمات', conditions: 'الشروط لا تنطبق', invalid: 'تالفة' };

const modeBadge = computed(() => {
  if (!props.flag?.on) return { label: 'مطفأ: النموذج يرد على كل شيء', cls: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' };
  if (props.mode === 'live') return { label: 'حي: القواعد ترد على العملاء', cls: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' };
  return { label: 'ظل: يُقيَّم ولا يُرسل', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' };
});

const conditionsText = (r) => {
  const c = r.conditions || {};
  const parts = [];
  if (c.msg_types) parts.push('الأنواع: ' + c.msg_types.map((t) => props.msgTypes[t] || t).join('، '));
  if (c.linked === 'yes') parts.push('للمربوط بحساب');
  if (c.linked === 'no') parts.push('لغير المربوط');
  if (c.topics_any?.length) parts.push('المواضيع: ' + c.topics_any.map((t) => props.topics[t] || t).join('، '));
  if (c.has_referral === true) parts.push('من إعلان');
  if (c.has_referral === false) parts.push('ليس من إعلان');
  if (c.exclude_staff === false) parts.push('ويرد على أرقام الموظفين');
  return parts.join(' · ');
};

// ===== المحرّر =====
const showEditor = ref(false);
const editing = ref(null);
const editorErrors = ref({});
const saving = ref(false);
const editor = ref(null);

function openEditor(r = null) {
  editing.value = r;
  editorErrors.value = {};
  showEditor.value = true;
}

function save(payload) {
  saving.value = true;
  const url = editing.value ? `/whatsapp/auto-rules/${editing.value.id}` : '/whatsapp/auto-rules';
  router[editing.value ? 'put' : 'post'](url, payload, {
    preserveScroll: true,
    onSuccess: () => { editor.value?.markSaved(); showEditor.value = false; },
    onError: (errors) => { editorErrors.value = errors; },
    onFinish: () => { saving.value = false; },
  });
}

async function remove(r) {
  if (!(await confirm({ title: 'حذف قاعدة', message: `حذف «${r.name}»؟ تبقى نسختها الكاملة في سجل التدقيق.`, confirmLabel: '🗑️ حذف', danger: true }))) return;
  router.delete(`/whatsapp/auto-rules/${r.id}`, { preserveScroll: true });
}

function toggle(r) {
  router.post(`/whatsapp/auto-rules/${r.id}/toggle`, { on: !r.is_active }, { preserveScroll: true });
}

function move(i, dir) {
  const ids = props.rules.map((r) => r.id);
  const j = i + dir;
  [ids[i], ids[j]] = [ids[j], ids[i]];
  router.post('/whatsapp/auto-rules/reorder', { ids }, { preserveScroll: true });
}

// ===== الوضع (ظل أو حي): حفظ جزئي للإعدادات برمز النسخة، وحقل حساس بتأكيد =====
const modeBusy = ref(false);
const modeError = ref('');
async function switchMode() {
  const next = props.mode === 'live' ? 'shadow' : 'live';
  const ok = await confirm(next === 'live'
    ? { title: 'الإرسال الفعلي', message: 'تبدأ القواعد المفعّلة بالرد على العملاء بنصها الثابت. هل مرّ أسبوع الظل وراجعتَ المطابقات؟', confirmLabel: '▶️ تحويل', danger: true }
    : { title: 'العودة إلى الظل', message: 'تتوقف القواعد عن الإرسال وتبقى تُعدّ، ويجيب النموذج على كل شيء.', confirmLabel: '⏸️ العودة' });
  if (!ok) return;
  modeBusy.value = true; modeError.value = '';
  try {
    await axios.patch('/whatsapp/settings', { version: props.settingsVersion, changes: { auto_rules_mode: next }, confirm: true });
    router.reload({ only: ['mode', 'settingsVersion'] });
  } catch (e) {
    modeError.value = e.response?.status === 409 ? 'تغيّرت الإعدادات عند غيرك — أعد تحميل الصفحة ثم حاول.' : (e.response?.data?.message || 'تعذّر الحفظ');
  } finally {
    modeBusy.value = false;
  }
}

// ===== جرّب نصاً =====
const testText = ref('');
const testLinked = ref('no');
const testing = ref(false);
const testResult = ref(null);
const testError = ref('');
async function runTest() {
  testing.value = true; testError.value = '';
  try {
    const { data } = await axios.post('/api/whatsapp/auto-rules/test', { text: testText.value, linked: testLinked.value });
    testResult.value = data;
    if (data.match) router.reload({ only: ['rules'] });
  } catch (e) {
    testError.value = e.response?.status === 429 ? 'تجارب كثيرة — انتظر دقيقة.' : 'تعذّرت التجربة';
  } finally {
    testing.value = false;
  }
}

// ===== المحاكاة =====
const simulating = ref(false);
const simulation = ref(null);
const simulateError = ref('');
const sim = (id) => simulation.value?.rules.find((r) => r.id === id) || null;
const percent = (share) => `${Math.round((share || 0) * 1000) / 10}%`;
async function simulate() {
  simulating.value = true; simulateError.value = '';
  try {
    const { data } = await axios.post('/api/whatsapp/auto-rules/simulate');
    simulation.value = data;
    router.reload({ only: ['rules'] });
  } catch (e) {
    simulateError.value = e.response?.status === 429 ? 'محاكاة كثيرة — انتظر دقيقة.' : 'تعذّرت المحاكاة';
  } finally {
    simulating.value = false;
  }
}
</script>
