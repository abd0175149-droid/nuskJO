<template>
  <!-- خطأ في مكوّن الصفحة (E28): بطاقة بزر بدل الصفحة البيضاء، بلا نص الخطأ -->
  <div v-if="state.pageError && !hidden" role="alert" data-test="page-error"
       class="mb-3 rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3.5 text-sm text-red-800 dark:text-red-200 flex flex-wrap items-center gap-3">
    <span class="flex-1 min-w-0 font-bold">⚠️ حدث خطأ في هذه الصفحة — أعد التحميل</span>
    <button type="button" @click="reload" class="min-h-9 px-3 rounded-lg text-xs font-bold bg-red-600 text-white hover:bg-red-700">إعادة التحميل</button>
    <button type="button" @click="hidden = true" aria-label="إخفاء" class="min-h-9 px-2 text-xs font-bold hover:text-red-950 dark:hover:text-red-50">✕</button>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useConnectionStatus } from '@/composables/useConnectionStatus.js';

const { state } = useConnectionStatus();
const hidden = ref(false);
// خطأ جديد بعد الإخفاء يُظهر البطاقة من جديد
watch(() => state.pageError, (on) => { if (on) hidden.value = false; });
const reload = () => window.location.reload();
</script>
