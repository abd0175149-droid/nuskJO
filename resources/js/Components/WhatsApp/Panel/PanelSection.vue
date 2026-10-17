<template>
  <!-- قسم قابل للطي في اللوحة الجانبية (C128): مطويّاً يعرض سطر ملخص، وحالته تُحفظ للمتصفح لكل قسم -->
  <section class="border-b border-gray-100 dark:border-gray-800" :data-test="`panel-section-${id}`">
    <h3 class="m-0">
      <button type="button" @click="toggle" :aria-expanded="open ? 'true' : 'false'" :aria-controls="bodyId"
              class="w-full min-h-11 px-4 py-2 flex items-center gap-2 text-start hover:bg-gray-50 dark:hover:bg-gray-800/60">
        <span aria-hidden="true" class="shrink-0 text-base leading-none">{{ icon }}</span>
        <span class="shrink-0 text-[13px] font-bold text-gray-800 dark:text-gray-100">{{ title }}</span>
        <span v-if="count !== null && count !== undefined" class="shrink-0 min-w-5 px-1.5 rounded-full text-[10px] font-bold text-center bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 tabular-nums">{{ count }}</span>
        <bdi v-if="!open && summary" dir="auto" class="min-w-0 truncate text-[11px] text-gray-500 dark:text-gray-400 [unicode-bidi:plaintext]">{{ summary }}</bdi>
        <span class="ms-auto shrink-0 text-gray-400 text-xs transition-transform" :class="open ? 'rotate-180' : ''" aria-hidden="true">▾</span>
      </button>
    </h3>
    <div v-show="open" :id="bodyId" class="px-4 pb-3 pt-0.5">
      <slot/>
    </div>
  </section>
</template>

<script setup>
import { ref, useId } from 'vue';

const props = defineProps({
  id: { type: String, required: true },
  icon: { type: String, default: '' },
  title: { type: String, required: true },
  summary: { type: String, default: '' },
  count: { type: Number, default: null },
  defaultOpen: Boolean,
});

const STORE = `wa:panel:section:${props.id}`;
const bodyId = `panel-${props.id}-${useId()}`;
const read = () => {
  try {
    const saved = localStorage.getItem(STORE);
    if (saved !== null) return saved === '1';
  } catch { /* التخزين غير متاح */ }
  return props.defaultOpen;
};
const open = ref(read());
function toggle() {
  open.value = !open.value;
  try { localStorage.setItem(STORE, open.value ? '1' : '0'); } catch { /* التخزين غير متاح */ }
}

defineExpose({ open: () => { if (!open.value) toggle(); } });
</script>
