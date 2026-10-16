<template>
  <AppModal :open="open" :title="rule ? 'تعديل قاعدة رد حتمية' : 'قاعدة رد حتمية جديدة'" size="lg" :dirty="dirty" @close="$emit('close')">
    <form @submit.prevent="submit" class="space-y-4" data-test="rule-editor">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label for="ar-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اسم القاعدة *</label>
          <input id="ar-name" data-autofocus v-model="draft.name" maxlength="80" required placeholder="مثال: موقع المكتب"
                 class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
          <p v-if="errors.name" class="mt-1 text-xs text-red-500" role="alert">{{ errors.name }}</p>
        </div>
        <div>
          <label for="ar-type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">نوع المطابقة</label>
          <select id="ar-type" v-model="draft.match_type" class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm">
            <option v-for="(label, key) in MATCH_TYPES" :key="key" :value="key">{{ label }}</option>
          </select>
        </div>
      </div>

      <!-- قيم المطابقة: كلمات كاملة حرفية، بلا تعابير نمطية -->
      <div>
        <label for="ar-pattern" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          قيم المطابقة * <span class="text-xs font-normal text-gray-400" dir="ltr">{{ draft.patterns.length }} / {{ limits.patterns }}</span>
        </label>
        <div class="flex flex-wrap gap-1.5 mb-1.5" data-test="patterns">
          <span v-for="(p, i) in draft.patterns" :key="p + i" dir="auto"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-teal-50 text-teal-800 dark:bg-teal-900/30 dark:text-teal-200 [unicode-bidi:plaintext]">
            {{ p }}
            <button type="button" @click="draft.patterns.splice(i, 1)" :aria-label="`حذف القيمة ${p}`" class="leading-none hover:text-red-600">✕</button>
          </span>
        </div>
        <div class="flex gap-2">
          <input id="ar-pattern" v-model="patternInput" :maxlength="limits.pattern_len" @keydown.enter.prevent="addPattern" dir="auto"
                 :disabled="draft.patterns.length >= limits.patterns" placeholder="اكتب قيمة ثم Enter"
                 class="w-full min-w-0 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm disabled:opacity-50"/>
          <button type="button" @click="addPattern" :disabled="!patternInput.trim() || draft.patterns.length >= limits.patterns"
                  class="shrink-0 px-3 py-2 rounded-xl text-sm font-bold border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-40">+ إضافة</button>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ MATCH_HINTS[draft.match_type] }} الترقيم والتشكيل والهمزات والأرقام الهندية لا تؤثر، و«/الموقع» تساوي «الموقع».</p>
        <p v-if="patternError" class="mt-1 text-xs text-red-500" role="alert">{{ patternError }}</p>
      </div>

      <!-- الشروط (كلها معاً) -->
      <fieldset class="rounded-xl border border-gray-200 dark:border-gray-700 p-3 space-y-3 min-w-0">
        <legend class="px-1 text-sm font-bold text-gray-700 dark:text-gray-200">الشروط</legend>
        <div class="flex flex-wrap gap-x-4 gap-y-2" role="group" aria-label="أنواع الرسائل">
          <label v-for="(label, key) in msgTypes" :key="key" class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
            <input type="checkbox" :value="key" v-model="draft.msg_types" class="w-4 h-4 rounded text-gold-500"/> {{ label }}
          </label>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label for="ar-linked" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">الربط بعميل</label>
            <select id="ar-linked" v-model="draft.linked" class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm">
              <option value="any">أي عميل</option>
              <option value="yes">مربوط بحساب فقط</option>
              <option value="no">غير مربوط فقط</option>
            </select>
          </div>
          <div>
            <label for="ar-referral" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">جاء من إعلان</label>
            <select id="ar-referral" v-model="draft.referral" class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm">
              <option value="any">لا يهم</option>
              <option value="yes">من إعلان فقط</option>
              <option value="no">ليس من إعلان</option>
            </select>
          </div>
        </div>
        <div>
          <p class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">المواضيع (أي واحد منها؛ فارغ = كل المحادثات)</p>
          <div class="flex flex-wrap gap-1.5" role="group" aria-label="المواضيع">
            <button v-for="(label, key) in topics" :key="key" type="button" @click="toggleTopic(key)" :aria-pressed="draft.topics_any.includes(key)"
                    class="px-2 py-0.5 rounded-full text-xs border"
                    :class="draft.topics_any.includes(key) ? 'border-gold-400 bg-gold-50 text-gold-800 dark:bg-gold-900/20 dark:text-gold-200' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300'">
              {{ label }}
            </button>
          </div>
        </div>
        <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
          <input type="checkbox" v-model="draft.exclude_staff" class="w-4 h-4 rounded text-gold-500"/> لا ترد على أرقام الموظفين
        </label>
        <p v-if="conditionsError" class="text-xs text-red-500" role="alert">{{ conditionsError }}</p>
      </fieldset>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label for="ar-words" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">حد الكلمات</label>
          <input id="ar-words" v-model.number="draft.max_words" type="number" min="1" max="20" dir="ltr"
                 class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">رسالة أطول يجيبها النموذج.</p>
          <p v-if="errors.max_words" class="text-xs text-red-500">{{ errors.max_words }}</p>
        </div>
        <div>
          <label for="ar-cooldown" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">التهدئة (دقائق)</label>
          <input id="ar-cooldown" v-model.number="draft.cooldown_minutes" type="number" min="5" max="10080" dir="ltr"
                 class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">لا يتكرر الرد نفسه للمحادثة قبلها.</p>
          <p v-if="errors.cooldown_minutes" class="text-xs text-red-500">{{ errors.cooldown_minutes }}</p>
        </div>
      </div>

      <!-- النصان: بلا أسعار ولا دفع ولا هاتف مكتوب؛ البيانات الثابتة من متغيرات الشركة -->
      <div v-for="f in TEXTS" :key="f.key">
        <label :for="`ar-${f.key}`" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ f.label }}</label>
        <div class="flex flex-wrap gap-1 mb-1.5" role="group" :aria-label="`إدراج متغير في ${f.label}`">
          <button v-for="(label, key) in variables" :key="key" type="button" @click="insertVar(f.key, key)" :title="label"
                  class="px-2 py-0.5 rounded-lg text-[11px] font-mono border border-gray-200 dark:border-gray-700 hover:border-gold-400" dir="ltr">{{ token(key) }}</button>
        </div>
        <textarea :id="`ar-${f.key}`" :ref="(el) => (textEls[f.key] = el)" v-model="draft[f.key]" rows="3" :maxlength="limits.reply" :dir="f.dir"
                  :required="f.key === 'reply_text_ar'"
                  class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm leading-relaxed resize-y [unicode-bidi:plaintext]"></textarea>
        <p class="text-xs text-gray-400"><span class="font-mono" dir="ltr">{{ (draft[f.key] || '').length }} / {{ limits.reply }}</span><template v-if="f.hint"> · {{ f.hint }}</template></p>
        <p v-if="errors[f.key]" class="mt-1 text-xs text-red-500" role="alert" :data-test="`error-${f.key}`">{{ errors[f.key] }}</p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label for="ar-topic" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">وسم موضوع (اختياري)</label>
          <select id="ar-topic" v-model="draft.tag_topic" class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm">
            <option :value="null">— بلا وسم</option>
            <option v-for="(label, key) in topics" :key="key" :value="key">{{ label }}</option>
          </select>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">يوكل المختص الأساسي بالموضوع بصمت.</p>
        </div>
        <div>
          <label for="ar-command" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">أمر رسمي مرتبط (اختياري)</label>
          <input id="ar-command" v-model="draft.command_name" maxlength="32" dir="auto" placeholder="مثال: الموقع"
                 class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
          <p v-if="errors.command_name" class="text-xs text-red-500">{{ errors.command_name }}</p>
        </div>
      </div>

      <p v-if="rule?.is_active" class="text-xs font-bold text-amber-700 dark:text-amber-400">⚠️ تعديل القيم أو الشروط أو النص يوقف القاعدة حتى تُجرَّب من جديد.</p>
      <div class="flex gap-3 pt-1">
        <button type="submit" :disabled="processing || !draft.patterns.length" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">حفظ</button>
        <button type="button" @click="$emit('close')" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">إلغاء</button>
      </div>
    </form>
  </AppModal>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';

// محرّر قاعدة رد حتمية (E37): مسودة محلية تُرسل كاملة عند الحفظ، والتحقق النهائي وحارس النص في الخادم
const props = defineProps({
  open: Boolean,
  rule: { type: Object, default: null },
  errors: { type: Object, default: () => ({}) },
  processing: Boolean,
  topics: { type: Object, default: () => ({}) },
  variables: { type: Object, default: () => ({}) },
  msgTypes: { type: Object, default: () => ({}) },
  limits: { type: Object, default: () => ({ patterns: 10, pattern_len: 60, reply: 1024 }) },
});
const emit = defineEmits(['submit', 'close']);

const MATCH_TYPES = { exact: 'مطابقة تامة', contains: 'تحوي كل القيم', any_of: 'تحوي أي قيمة' };
const MATCH_HINTS = {
  exact: 'الرسالة كلها تساوي إحدى القيم (للأوامر والأسئلة المقترحة).',
  contains: 'كل القيم موجودة في الرسالة ككلمات كاملة.',
  any_of: 'قيمة واحدة على الأقل موجودة ككلمة كاملة.',
};
const DEFAULT_TYPES = ['text', 'interactive', 'button'];
const TEXTS = [
  { key: 'reply_text_ar', label: 'نص الرد بالعربية *', dir: 'rtl', hint: 'بلا أسعار ولا بيانات دفع ولا رقم هاتف مكتوب' },
  { key: 'reply_text_en', label: 'نص الرد بالإنجليزية (اختياري)', dir: 'ltr', hint: 'بلاه يجيب النموذج من كتب بالإنجليزية' },
];

const token = (key) => `{${key}}`;

const fromRule = (r) => {
  const c = r?.conditions || {};
  return {
    name: r?.name || '',
    match_type: r?.match_type || 'any_of',
    patterns: [...(r?.patterns || [])],
    msg_types: [...(c.msg_types || DEFAULT_TYPES)],
    linked: c.linked || 'any',
    referral: c.has_referral === undefined ? 'any' : (c.has_referral ? 'yes' : 'no'),
    topics_any: [...(c.topics_any || [])],
    exclude_staff: c.exclude_staff !== false,
    referral_source_ids: [...(c.referral_source_ids || [])],
    max_words: r?.max_words ?? 6,
    cooldown_minutes: r?.cooldown_minutes ?? 360,
    reply_text_ar: r?.reply_text_ar || '',
    reply_text_en: r?.reply_text_en || '',
    tag_topic: r?.tag_topic || null,
    command_name: r?.command_name || '',
  };
};

const draft = reactive(fromRule(props.rule));
const baseline = ref(JSON.stringify(draft));
const patternInput = ref('');
const textEls = {};
watch(() => [props.open, props.rule], () => {
  if (!props.open) return;
  Object.assign(draft, fromRule(props.rule));
  baseline.value = JSON.stringify(draft);
  patternInput.value = '';
});
const dirty = computed(() => props.open && JSON.stringify(draft) !== baseline.value);

const patternError = computed(() => props.errors.patterns || Object.entries(props.errors).find(([k]) => k.startsWith('patterns.'))?.[1] || '');
const conditionsError = computed(() => Object.entries(props.errors).find(([k]) => k.startsWith('conditions'))?.[1] || '');

function addPattern() {
  const value = patternInput.value.trim();
  if (!value || draft.patterns.length >= props.limits.patterns || draft.patterns.includes(value)) return;
  draft.patterns.push(value.slice(0, props.limits.pattern_len));
  patternInput.value = '';
}

function toggleTopic(key) {
  const i = draft.topics_any.indexOf(key);
  if (i === -1) draft.topics_any.push(key);
  else draft.topics_any.splice(i, 1);
}

async function insertVar(field, key) {
  const el = textEls[field];
  const text = draft[field] || '';
  const at = el ? el.selectionStart : text.length;
  draft[field] = text.slice(0, at) + token(key) + text.slice(el ? el.selectionEnd : at);
  await nextTick();
  el?.focus();
  el?.setSelectionRange(at + token(key).length, at + token(key).length);
}

/** الشروط المختلفة عن الافتراضي وحدها: أقل ما يمكن من مفاتيح (5 على الأكثر في الخادم) */
const conditions = () => {
  const c = {};
  if ([...draft.msg_types].sort().join() !== [...DEFAULT_TYPES].sort().join()) c.msg_types = [...draft.msg_types];
  if (draft.linked !== 'any') c.linked = draft.linked;
  if (draft.topics_any.length) c.topics_any = [...draft.topics_any];
  if (draft.referral !== 'any') c.has_referral = draft.referral === 'yes';
  if (draft.referral_source_ids.length) c.referral_source_ids = [...draft.referral_source_ids];
  if (!draft.exclude_staff) c.exclude_staff = false;
  return Object.keys(c).length ? c : null;
};

function submit() {
  if (patternInput.value.trim()) addPattern();
  emit('submit', {
    name: draft.name,
    match_type: draft.match_type,
    patterns: [...draft.patterns],
    conditions: conditions(),
    max_words: draft.max_words,
    cooldown_minutes: draft.cooldown_minutes,
    reply_text_ar: draft.reply_text_ar,
    reply_text_en: draft.reply_text_en || null,
    tag_topic: draft.tag_topic,
    command_name: draft.command_name || null,
  });
}

defineExpose({ markSaved: () => { baseline.value = JSON.stringify(draft); } });
</script>
