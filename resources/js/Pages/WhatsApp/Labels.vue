<template>
  <AppLayout>
    <template #header>تصنيفات المحادثات</template>

    <div class="space-y-6 pb-4">
      <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
      <div v-if="pageError" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300" role="alert">❌ {{ pageError }}</div>

      <div class="p-4 rounded-xl border text-sm leading-relaxed border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-amber-900 dark:text-amber-200" data-test="labels-warning">
        🔒 التصنيفات داخلية لا يراها العميل، ولا تغيّر من يرى المحادثة. لا تكتب فيها أسماء أو أرقاماً.
        <span class="block mt-1 text-xs text-amber-800 dark:text-amber-300">يختار الموظف منها فقط، حتى {{ max }} تصنيفات للمحادثة. والمعطّل يبقى على المحادثات القديمة بلون باهت.</span>
        <span v-if="!enabled" class="block mt-2 font-bold">⚠️ لا تظهر في الصندوق بعد: العلم «تصنيفات سير العمل» مطفأ في إعدادات الواتساب.</span>
      </div>

      <div class="flex justify-between items-center gap-3 flex-wrap">
        <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">التصنيفات <span class="text-xs font-normal text-gray-400">({{ labels.length }})</span></h3>
        <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ تصنيف جديد</button>
      </div>

      <ul v-if="labels.length" class="space-y-2">
        <li v-for="(l, i) in labels" :key="l.id" class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-3 sm:p-4 flex flex-wrap items-center gap-3"
            :class="l.active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600'" data-test="label-row">
          <span class="px-2 py-0.5 rounded-lg text-sm ring-1" :class="labelClass(l.color, l.active)"><bdi dir="auto">{{ l.name }}</bdi></span>
          <span class="text-xs text-gray-500 dark:text-gray-400">{{ l.usage }} محادثة</span>
          <span v-if="!l.active" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">معطّل</span>
          <div class="flex items-center gap-1 ms-auto">
            <button type="button" @click="move(i, -1)" :disabled="i === 0" :aria-label="'تحريك ' + l.name + ' للأعلى'" class="min-w-9 min-h-9 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-30">▲</button>
            <button type="button" @click="move(i, 1)" :disabled="i === labels.length - 1" :aria-label="'تحريك ' + l.name + ' للأسفل'" class="min-w-9 min-h-9 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-30">▼</button>
            <button type="button" @click="setActive(l, !l.active)" class="px-2 py-1 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">{{ l.active ? '⏸️ تعطيل' : '▶️ تفعيل' }}</button>
            <button type="button" @click="openForm(l)" class="px-2 py-1 rounded-lg text-xs font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ تعديل</button>
            <button type="button" @click="remove(l)" :aria-label="'حذف ' + l.name" title="حذف" class="min-w-9 min-h-9 rounded-lg text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">🗑️</button>
          </div>
        </li>
      </ul>
      <div v-else class="rounded-xl border-2 border-dashed p-10 text-center space-y-3 border-gray-300 dark:border-gray-600">
        <p class="text-sm text-gray-500 dark:text-gray-400">لا تصنيفات بعد. المقترح: «ينتظر جواز/وثائق»، «دفع عربون»، «تأشيرة قيد الإصدار»، «VIP».</p>
        <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200">+ أضف أول تصنيف</button>
      </div>
    </div>

    <AppModal :open="showForm" :title="editing ? 'تعديل تصنيف' : 'تصنيف جديد'" size="md" :dirty="dirty" @close="showForm = false">
      <form @submit.prevent="save" class="space-y-4">
        <div>
          <label for="label-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الاسم *</label>
          <input id="label-name" data-autofocus v-model="form.name" maxlength="30" required dir="auto" placeholder="مثال: ينتظر جواز"
                 class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm [unicode-bidi:plaintext]"/>
          <p class="text-xs text-gray-400 mt-1"><span dir="ltr" class="font-mono">{{ form.name.length }} / 30</span> · بلا أرقام طويلة ولا بريد ولا روابط</p>
          <p v-if="form.errors.name" class="mt-1 text-xs text-red-500" role="alert">{{ form.errors.name }}</p>
        </div>
        <fieldset>
          <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اللون</legend>
          <div class="flex flex-wrap gap-2">
            <button v-for="c in colors" :key="c" type="button" @click="form.color = c" :aria-pressed="form.color === c" :aria-label="COLOR_NAMES[c] || c" :title="COLOR_NAMES[c] || c"
                    class="w-9 h-9 rounded-full ring-offset-2 dark:ring-offset-gray-900" :class="[LABEL_SWATCHES[c], form.color === c ? 'ring-2 ring-gray-800 dark:ring-gray-100' : '']"></button>
          </div>
          <p v-if="form.errors.color" class="mt-1 text-xs text-red-500" role="alert">{{ form.errors.color }}</p>
        </fieldset>
        <div>
          <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">معاينة</p>
          <span class="px-2 py-0.5 rounded-lg text-sm ring-1" :class="labelClass(form.color)"><bdi dir="auto">{{ form.name || 'اسم التصنيف' }}</bdi></span>
        </div>
        <div class="flex gap-3 pt-1">
          <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">حفظ</button>
          <button type="button" @click="showForm = false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">إلغاء</button>
        </div>
      </form>
    </AppModal>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import AppModal from '@/Components/AppModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { COLOR_NAMES, LABEL_SWATCHES, labelClass } from '@/composables/whatsapp/useLabels.js';

const props = defineProps({
  labels: { type: Array, default: () => [] },
  colors: { type: Array, default: () => [] },
  max: { type: Number, default: 5 },
  enabled: Boolean,
});

const { confirm } = useConfirm();
const showForm = ref(false);
const editing = ref(null);
const pageError = ref('');
const form = useForm({ name: '', color: 'gray', is_active: true });
const baseline = ref('');
const dirty = computed(() => showForm.value && JSON.stringify(form.data()) !== baseline.value);

function openForm(l = null) {
  editing.value = l;
  form.clearErrors();
  form.name = l?.name || '';
  form.color = l?.color || 'amber';
  form.is_active = l ? l.active : true;
  baseline.value = JSON.stringify(form.data());
  showForm.value = true;
}

function save() {
  const opts = { preserveScroll: true, onSuccess: () => { baseline.value = JSON.stringify(form.data()); showForm.value = false; } };
  if (editing.value) form.put(`/whatsapp/labels/${editing.value.id}`, opts);
  else form.post('/whatsapp/labels', opts);
}

const errorsOf = (errors) => Object.values(errors || {})[0] || 'تعذّر الحفظ';

function setActive(l, active) {
  pageError.value = '';
  router.put(`/whatsapp/labels/${l.id}`, { name: l.name, color: l.color, is_active: active }, { preserveScroll: true, onError: (e) => { pageError.value = errorsOf(e); } });
}

async function remove(l) {
  pageError.value = '';
  const used = l.usage > 0;
  const ok = await confirm({
    title: 'حذف تصنيف',
    message: used
      ? `«${l.name}» على ${l.usage} محادثة. الحذف يزيله عنها كلها. البديل الأخف: التعطيل.`
      : `حذف «${l.name}»؟ يبقى أثره في سجل التدقيق.`,
    confirmLabel: used ? '🗑️ إزالة من كل المحادثات وحذف' : '🗑️ حذف',
    danger: true,
  });
  if (!ok) return;
  router.delete(`/whatsapp/labels/${l.id}`, { data: { detach_all: used }, preserveScroll: true, onError: (e) => { pageError.value = errorsOf(e); } });
}

function move(i, dir) {
  const ids = props.labels.map((l) => l.id);
  const j = i + dir;
  [ids[i], ids[j]] = [ids[j], ids[i]];
  router.post('/whatsapp/labels/reorder', { ids }, { preserveScroll: true });
}
</script>
