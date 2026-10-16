<template>
  <!-- منتقي تفاعل الموظف (E33): القائمة البيضاء من الخادم وحدها، و«إزالة» لتفاعل سابق -->
  <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="اختر تفاعلاً">
    <button v-for="e in emojis" :key="e" type="button" :disabled="busy" @click="$emit('pick', e)" :aria-label="`تفاعل ${e}`" :aria-pressed="current === e"
            data-test="reaction-option"
            class="min-w-11 min-h-11 rounded-xl text-xl border disabled:opacity-50"
            :class="current === e ? 'border-gold-500 bg-gold-50 dark:bg-gold-900/30' : 'border-gray-200 dark:border-gray-700 hover:border-gold-400'">{{ e }}</button>
    <button v-if="current" type="button" :disabled="busy" @click="$emit('pick', '')" data-test="reaction-remove"
            class="min-h-11 px-3 rounded-xl text-xs font-bold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 disabled:opacity-50">إزالة</button>
  </div>
</template>

<script setup>
defineProps({
  emojis: { type: Array, default: () => [] },
  current: { type: String, default: '' },
  busy: Boolean,
});
defineEmits(['pick']);
</script>
