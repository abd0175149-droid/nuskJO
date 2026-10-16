<template>
  <!-- «اكتب بالعربية وأترجم» (E29): مع التفعيل يفتح «إرسال» معاينة الترجمة بدل الإرسال المباشر -->
  <div class="px-3 pt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" data-test="translate-bar">
    <label class="inline-flex items-center gap-1.5 min-h-9 cursor-pointer select-none">
      <input type="checkbox" :checked="modelValue" @change="$emit('update:modelValue', $event.target.checked)" class="w-4 h-4 rounded text-gold-500"/>
      <span class="font-bold text-gray-700 dark:text-gray-200">🌐 اكتب بالعربية وأترجم</span>
    </label>
    <template v-if="modelValue">
      <span class="inline-flex items-center gap-1.5">
        <label :for="selectId" class="text-gray-500 dark:text-gray-400">إلى</label>
        <select :id="selectId" :value="target" @change="$emit('update:target', $event.target.value)"
                class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs dark:text-white">
          <option v-for="(label, code) in targets" :key="code" :value="code">{{ label }}</option>
        </select>
      </span>
      <span class="text-[11px] text-gray-500 dark:text-gray-400">«إرسال» يعرض الترجمة للمراجعة أولاً</span>
    </template>
  </div>
</template>

<script setup>
import { useId } from 'vue';

defineProps({
  modelValue: Boolean,
  target: { type: String, default: 'en' },
  targets: { type: Object, default: () => ({}) },
});
defineEmits(['update:modelValue', 'update:target']);

const selectId = `tr-target-${useId()}`;
</script>
