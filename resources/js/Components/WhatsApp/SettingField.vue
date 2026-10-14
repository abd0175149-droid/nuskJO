<template>
    <div>
        <div class="flex items-center justify-between gap-2 mb-1">
            <label :for="id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ field.label }}</label>
            <button v-if="modelValue !== defaultValue" type="button" @click="$emit('update:modelValue', defaultValue)"
                    class="text-xs text-gray-500 dark:text-gray-400 hover:text-gold-700 dark:hover:text-gold-400" :title="`القيمة الافتراضية: ${defaultValue}`">↺ افتراضي</button>
        </div>
        <input :id="id" :value="modelValue" @input="$emit('update:modelValue', $event.target.value === '' ? null : Number($event.target.value))"
               type="number" :min="field.min" :max="field.max" dir="ltr"
               class="w-full px-4 py-2.5 rounded-xl border bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"
               :class="[error ? 'border-red-400 dark:border-red-600' : 'border-gray-200 dark:border-gray-700', dirty ? 'ring-1 ring-gold-400' : '']"/>
        <p v-if="error" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ error }}</p>
        <p v-else class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ field.help }} ({{ field.min }}–{{ field.max }})</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';

// حقل رقمي مولَّد من SettingsSchema::uiFields(): التسمية والمساعدة والحدود والافتراض من الخادم وحده
const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { type: Number, default: null },
    defaultValue: { type: Number, default: null },
    error: { type: String, default: '' },
    dirty: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);

const id = computed(() => 'wa-field-' + props.field.key);
</script>
