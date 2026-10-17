<template>
  <!-- نافذة على <dialog> الأصلي (E16.9): showModal يجعل الخلفية inert ويضع النافذة في الطبقة العليا بلا z-index ولا Teleport.
       m-auto لأن preflight في Tailwind يصفّر هامش dialog الذي يتوسّط به -->
  <div v-if="fallback" class="fixed inset-0 z-[59] bg-black/50" aria-hidden="true" @click="requestClose"></div>
  <dialog ref="dlg" :aria-labelledby="titleId" @cancel="onCancel" @close="onNativeClose" @click="onClick"
          class="app-modal m-auto p-0 border-0 rounded-2xl shadow-2xl bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-100 w-[calc(100%-2rem)] max-h-[85dvh] backdrop:bg-black/50"
          :class="[SIZES[size] || SIZES.md, fallback ? 'fixed inset-0 z-[60]' : '']">
    <div class="flex flex-col max-h-[85dvh]">
      <header class="flex items-center justify-between gap-3 px-5 py-3 border-b border-gray-200 dark:border-gray-700 shrink-0">
        <h2 :id="titleId" class="text-base font-bold [text-wrap:balance]">{{ title }}</h2>
        <button type="button" @click="requestClose" :aria-label="closeLabel" :title="closeLabel"
                class="shrink-0 min-w-11 min-h-11 -me-2 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800">✕</button>
      </header>
      <!-- Escape أو الإغلاق ونموذج فيه تعديلات: تأكيد داخل النافذة نفسها لا نافذة فوقها -->
      <div v-if="askDiscard" role="alert" class="flex flex-wrap items-center gap-2 px-5 py-2.5 bg-amber-50 dark:bg-amber-900/30 text-amber-900 dark:text-amber-200 text-sm border-b border-amber-200 dark:border-amber-800 shrink-0">
        <span class="font-bold me-auto">تجاهل التعديلات؟</span>
        <button type="button" @click="discard" class="min-h-11 px-3 rounded-lg font-bold text-red-700 dark:text-red-300 hover:bg-red-100 dark:hover:bg-red-900/40">تجاهل</button>
        <button ref="keepBtn" type="button" @click="askDiscard = false" class="min-h-11 px-3 rounded-lg font-bold bg-white dark:bg-gray-800 border border-amber-300 dark:border-amber-700">متابعة التعديل</button>
      </div>
      <div ref="body" class="overflow-y-auto px-5 py-4 min-h-0">
        <slot />
      </div>
      <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 px-5 py-3 border-t border-gray-200 dark:border-gray-700 shrink-0">
        <slot name="footer" />
      </footer>
    </div>
  </dialog>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { setDirtyForm } from '@/utils/reloadOnce.js';

const props = defineProps({
  open: Boolean,
  title: { type: String, default: '' },
  // نموذج فيه تعديلات غير محفوظة: Escape والخلفية لا يغلقان بلا تأكيد
  dirty: Boolean,
  size: { type: String, default: 'md' },
  closeLabel: { type: String, default: 'إغلاق' },
});
const emit = defineEmits(['close']);

const SIZES = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };
const titleId = `modal-title-${useId()}`;
const dlg = ref(null);
const body = ref(null);
const keepBtn = ref(null);
const askDiscard = ref(false);
// متصفح بلا showModal (قديم): يُفتح بالسمة open مع خلفية مرسومة
const fallback = ref(false);
let opener = null;
// إغلاقات بدأناها نحن (open=false أو إزالة): حدث close الناتج عنها (متزامن أو لاحق بحسب المتصفح) ليس طلب إغلاق
let selfCloses = 0;

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

async function show() {
  const el = dlg.value;
  if (!el || el.open) return;
  opener = document.activeElement;
  askDiscard.value = false;
  if (typeof el.showModal === 'function') el.showModal();
  else { el.setAttribute('open', ''); fallback.value = true; }
  await nextTick();
  const target = el.querySelector('[data-autofocus]') || body.value?.querySelector(FOCUSABLE) || el.querySelector(FOCUSABLE);
  target?.focus();
}

function hide() {
  const el = dlg.value;
  askDiscard.value = false;
  if (el?.open) {
    if (typeof el.close === 'function' && !fallback.value) {
      selfCloses++;
      el.close();
    } else el.removeAttribute('open');
  }
  fallback.value = false;
  // التركيز يعود لمن فتح النافذة إن بقي في الصفحة
  if (opener && document.contains(opener)) opener.focus?.();
  opener = null;
}

function requestClose() {
  if (props.dirty) {
    askDiscard.value = true;
    nextTick(() => keepBtn.value?.focus());
    return;
  }
  emit('close');
}

function discard() {
  askDiscard.value = false;
  emit('close');
}

// Escape: يُعترض دائماً، والقرار للنافذة (dirty)
function onCancel(e) {
  e.preventDefault();
  requestClose();
}

// النقر على ::backdrop يصل إلى <dialog> نفسه (المحتوى يملؤه)
function onClick(e) {
  if (e.target === dlg.value) requestClose();
}

// أُغلقت من المتصفح (form method=dialog مثلاً) والأب ما زال يراها مفتوحة
function onNativeClose() {
  if (selfCloses > 0) { selfCloses--; return; }
  if (props.open) emit('close');
}

watch(() => props.open, (v) => (v ? show() : hide()));
onMounted(() => { if (props.open) show(); });
onBeforeUnmount(hide);

// نموذج مفتوح بتعديل غير محفوظ: لا إعادة تحميل تلقائية بعد النشر فوقه (E28)، بل شريط «تحديث متاح»
const dirtyId = titleId;
watch(() => props.open && props.dirty, (on) => setDirtyForm(dirtyId, on), { immediate: true });
onBeforeUnmount(() => setDirtyForm(dirtyId, false));
</script>
