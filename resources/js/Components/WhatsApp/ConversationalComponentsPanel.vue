<template>
  <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-4 sm:p-5 space-y-4 min-w-0" data-test="components-panel">
    <div>
      <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">📲 المكوّنات الرسمية في واتساب</h3>
      <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
        أسئلة يضغطها العميل عند أول محادثة، وأوامر تبدأ بـ «/». تصل رسالةً نصية عادية، فتجيبها قاعدة «مطابقة تامة» بدقة.
        لا تظهر الأسئلة لمن يفتح المحادثة من رابط برسالة جاهزة.
      </p>
      <p v-if="!state.enabled" class="mt-2 text-xs font-bold text-amber-700 dark:text-amber-400" data-test="components-off">
        ⚠️ المزامنة معطّلة: العلم «مزامنة الأوامر والأسئلة المقترحة» مطفأ في إعدادات الواتساب.
      </p>
    </div>

    <!-- أسئلة البداية (ice breakers) -->
    <div>
      <p class="text-sm font-bold text-gray-700 dark:text-gray-200 mb-2">أسئلة البداية <span class="text-xs font-normal text-gray-400">(حتى {{ limits.prompts }}، بلا إيموجي)</span></p>
      <div class="space-y-2">
        <div v-for="(p, i) in prompts" :key="'p' + i" class="flex items-center gap-2 min-w-0">
          <input v-model="prompts[i]" :maxlength="limits.prompt_len" dir="auto" :aria-label="`السؤال ${i + 1}`" :placeholder="PROMPT_HINTS[i] || ''"
                 class="w-full min-w-0 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
          <span class="shrink-0 text-[11px] font-mono text-gray-400" dir="ltr">{{ p.length }}/{{ limits.prompt_len }}</span>
          <span v-if="linkBadge('prompts', i, p) !== null" class="shrink-0 px-2 py-0.5 rounded-full text-[11px] font-bold" :class="badgeClass(linkBadge('prompts', i, p))">
            {{ linkBadge('prompts', i, p) ? 'مربوط بقاعدة' : 'بلا قاعدة' }}
          </span>
        </div>
      </div>
      <p v-if="fieldError('prompts')" class="mt-1 text-xs text-red-500" role="alert">{{ fieldError('prompts') }}</p>
    </div>

    <!-- الأوامر -->
    <div>
      <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">
        <p class="text-sm font-bold text-gray-700 dark:text-gray-200">الأوامر <span class="text-xs font-normal text-gray-400">(حتى {{ limits.commands }})</span></p>
        <button type="button" @click="commands.push({ command_name: '', command_description: '' })" :disabled="commands.length >= limits.commands"
                class="px-3 py-1 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-40">+ أمر</button>
      </div>
      <div class="space-y-2">
        <div v-for="(c, i) in commands" :key="'c' + i" class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] gap-2 items-center rounded-lg sm:rounded-none border sm:border-0 border-gray-100 dark:border-gray-800 p-2 sm:p-0">
          <div class="flex items-center gap-1 min-w-0">
            <span class="text-gray-400 font-mono" aria-hidden="true">/</span>
            <input v-model="c.command_name" :maxlength="limits.command_len" dir="auto" :aria-label="`اسم الأمر ${i + 1}`" placeholder="الموقع"
                   class="w-full min-w-0 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
          </div>
          <input v-model="c.command_description" :maxlength="limits.description_len" dir="auto" :aria-label="`تلميح الأمر ${i + 1}`" placeholder="عنوان المكتب ومواعيد الدوام"
                 class="w-full min-w-0 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
          <div class="flex items-center gap-1.5 justify-end">
            <span v-if="linkBadge('commands', i, c.command_name) !== null" class="px-2 py-0.5 rounded-full text-[11px] font-bold whitespace-nowrap" :class="badgeClass(linkBadge('commands', i, c.command_name))">
              {{ linkBadge('commands', i, c.command_name) ? 'مربوط بقاعدة' : 'بلا قاعدة' }}
            </span>
            <button type="button" @click="commands.splice(i, 1)" :aria-label="`حذف الأمر ${i + 1}`" class="min-w-9 min-h-9 rounded-lg text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">🗑️</button>
          </div>
        </div>
        <p v-if="!commands.length" class="text-xs text-gray-400">لا أوامر.</p>
      </div>
      <p v-if="fieldError('commands')" class="mt-1 text-xs text-red-500" role="alert">{{ fieldError('commands') }}</p>
    </div>

    <div class="flex flex-wrap gap-2">
      <button type="button" @click="sync(false)" :disabled="busy || !state.enabled" data-test="sync"
              class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-40">🔄 مزامنة مع واتساب</button>
      <button type="button" @click="read" :disabled="busy || !state.enabled" data-test="read"
              class="px-4 py-2 rounded-xl text-sm font-bold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40">👁 قراءة الحالي من واتساب</button>
      <button type="button" @click="sync(true)" :disabled="busy || !state.enabled" data-test="clear"
              class="px-4 py-2 rounded-xl text-sm font-bold text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/20 disabled:opacity-40">🧹 مسح من واتساب</button>
    </div>

    <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
      <p>آخر مزامنة: <bdi dir="ltr">{{ when(state.synced_at) }}</bdi></p>
      <p v-if="state.last_error" class="font-bold text-red-700 dark:text-red-400" data-test="last-error">
        آخر خطأ: <bdi dir="ltr" class="font-mono">{{ state.last_error.code }}<template v-if="state.last_error.meta_code"> ({{ state.last_error.meta_code }})</template></bdi>
        <template v-if="state.last_error.fbtrace_id"> · للدعم <bdi dir="ltr" class="font-mono">{{ state.last_error.fbtrace_id }}</bdi></template>
      </p>
    </div>
    <p v-if="message" role="status" class="text-xs" :class="messageOk ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'" data-test="message">{{ message }}</p>
    <ul v-if="warnings.length" class="text-xs text-amber-700 dark:text-amber-400 list-disc ps-5 space-y-0.5" data-test="warnings">
      <li v-for="w in warnings" :key="w">{{ w }}</li>
    </ul>
    <div v-if="live" class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-xs space-y-1" data-test="live">
      <p class="font-bold" :class="same ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400'">{{ same ? '✅ ما في واتساب يطابق المحفوظ' : '⚠️ ما في واتساب يختلف عن المحفوظ' }}</p>
      <p>الأسئلة في واتساب: <span dir="auto">{{ live.prompts.length ? live.prompts.join(' · ') : '—' }}</span></p>
      <p>الأوامر في واتساب: <span dir="auto">{{ live.commands.length ? live.commands.map((c) => '/' + c.command_name).join(' · ') : '—' }}</span></p>
    </div>
  </section>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { useConfirm } from '@/composables/useConfirm';
import { when } from '@/utils/waSettings.js';

// الأوامر وأسئلة البداية (E37): تُكتب في ميتا بنداء واحد من الخادم، ولا رمز ولا رد خام في الصفحة
const props = defineProps({
  initial: { type: Object, required: true },
  limits: { type: Object, default: () => ({ prompts: 4, prompt_len: 80, commands: 30, command_len: 32, description_len: 256 }) },
});

const PROMPT_HINTS = ['أين موقع مكتبكم؟', 'ما هي مواعيد الدوام؟', 'ما الوثائق المطلوبة للتأشيرة؟', 'أريد التحدث مع موظف'];

const { confirm } = useConfirm();
const state = ref({ ...props.initial });
const fill = (desired) => {
  const list = [...(desired?.prompts || [])];
  while (list.length < props.limits.prompts) list.push('');
  return list.slice(0, props.limits.prompts);
};
const prompts = ref(fill(props.initial.desired));
const commands = ref((props.initial.desired?.commands || []).map((c) => ({ ...c })));
const busy = ref(false);
const message = ref('');
const messageOk = ref(false);
const warnings = ref([]);
const errors = ref({});
const live = ref(null);
const same = ref(null);

// الشارة للمحفوظ وحده: «مربوط» إن وُجدت قاعدة exact مفعّلة تطابقه (حكم الخادم)
const linkBadge = (kind, i, value) => {
  const saved = kind === 'prompts' ? state.value.desired?.prompts?.[i] : state.value.desired?.commands?.[i]?.command_name;
  if (!value || value !== saved) return null;
  return !!state.value.linked?.[kind]?.[i];
};
const badgeClass = (linked) => (linked
  ? 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-200'
  : 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200');
const fieldError = (prefix) => Object.entries(errors.value).find(([k]) => k === prefix || k.startsWith(prefix + '.'))?.[1]?.[0] || '';

const payload = (clear) => (clear ? { prompts: [], commands: [] } : {
  prompts: prompts.value.map((p) => p.trim()).filter(Boolean),
  commands: commands.value.map((c) => ({ command_name: c.command_name.trim(), command_description: c.command_description.trim() }))
    .filter((c) => c.command_name || c.command_description),
});

async function sync(clear) {
  const ok = await confirm(clear
    ? { title: 'مسح من واتساب', message: 'تُحذف الأسئلة والأوامر من واتساب لكل العملاء. متابعة؟', confirmLabel: '🧹 مسح', danger: true }
    : { title: 'مزامنة مع واتساب', message: 'تظهر هذه الأسئلة والأوامر لكل من يراسلنا. متابعة؟', confirmLabel: '🔄 مزامنة' });
  if (!ok) return;
  busy.value = true; message.value = ''; warnings.value = []; errors.value = {};
  try {
    const { data } = await axios.post('/api/whatsapp/conversational-automation/sync', payload(clear));
    state.value = { ...state.value, ...data.state };
    warnings.value = data.warnings || [];
    messageOk.value = true;
    message.value = clear ? '✅ مُسحت من واتساب' : '✅ زُومنت مع واتساب';
    if (clear) { prompts.value = fill({ prompts: [] }); commands.value = []; }
  } catch (e) {
    messageOk.value = false;
    if (e.response?.status === 422) errors.value = e.response.data.errors || {};
    if (e.response?.data?.state) state.value = { ...state.value, ...e.response.data.state };
    message.value = '❌ ' + (e.response?.data?.error || e.response?.data?.message || 'تعذّرت المزامنة');
  } finally {
    busy.value = false;
  }
}

async function read() {
  busy.value = true; message.value = '';
  try {
    const { data } = await axios.get('/api/whatsapp/conversational-automation');
    live.value = data.live;
    same.value = data.same;
    state.value = { ...state.value, ...data.state };
  } catch (e) {
    messageOk.value = false;
    message.value = '❌ ' + (e.response?.data?.error || 'تعذّرت القراءة من واتساب');
  } finally {
    busy.value = false;
  }
}
</script>
