<template>
    <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 min-w-0">
        <header class="flex items-center justify-between gap-2 mb-3">
            <h3 class="font-bold text-gray-800 dark:text-gray-100">{{ title }}</h3>
            <span class="text-xs font-bold px-2.5 py-1 rounded-full border shrink-0" :class="levelClass(level)">{{ info.icon }} {{ info.label }}</span>
        </header>
        <dl class="space-y-1.5 text-sm">
            <div v-for="([k, v], i) in rows" :key="i" class="flex items-start justify-between gap-3 min-w-0">
                <dt class="text-gray-500 dark:text-gray-400 shrink-0">{{ k }}</dt>
                <dd class="text-gray-800 dark:text-gray-100 text-end break-words min-w-0">{{ v ?? '—' }}</dd>
            </div>
        </dl>
        <p v-if="note" class="text-xs text-red-700 dark:text-red-400 mt-2">{{ note }}</p>
    </section>
</template>

<script setup>
// بطاقة قسم في صفحة «حالة النظام» (E23): عنوان وشارة مستوى وصفوف مفتاح/قيمة نصية
import { computed } from 'vue';
import { level as levelOf, levelClass } from '@/utils/opsStatus.js';

const props = defineProps({
    title: { type: String, required: true },
    level: { type: String, default: 'unknown' },
    rows: { type: Array, default: () => [] },
    note: { type: String, default: '' },
});
const info = computed(() => levelOf(props.level));
</script>
