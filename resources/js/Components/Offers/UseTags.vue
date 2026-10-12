<template>
    <span class="inline-flex flex-wrap gap-1 align-middle ms-1">
        <span v-for="k in list" :key="k" class="px-1.5 rounded-full text-[10px] font-bold leading-4" :class="TAGS[k]?.cls" :title="TAGS[k]?.title">{{ TAGS[k]?.label }}</span>
    </span>
</template>

<script setup>
import { computed } from 'vue';

// من يقرأ هذا الحقل — الحقل يُدخل مرة واحدة ويقرؤه كل من هنا
const TAGS = {
    bot: { label: 'بوت', title: 'يقرؤه بوت الواتساب', cls: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' },
    card: { label: 'بطاقة', title: 'يظهر في بطاقة العرض', cls: 'bg-gold-100 text-gold-800 dark:bg-gold-900/40 dark:text-gold-200' },
    ready: { label: 'جاهزة', title: 'يظهر في قائمة العروض الجاهزة', cls: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' },
    inv: { label: 'فاتورة', title: 'تستخدمه فاتورة العرض', cls: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' },
    cost: { label: 'داخلي', title: 'تكلفة داخلية — لا تصل للبوت ولا للبطاقة', cls: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' },
};
const props = defineProps({ tags: { type: String, default: '' } });
const list = computed(() => props.tags.split(' ').filter(Boolean));
</script>
