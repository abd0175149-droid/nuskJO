<template>
    <span v-if="info" class="px-2 py-0.5 rounded-full text-xs font-bold whitespace-nowrap" :class="info.cls" :title="title">
        {{ info.label }}<template v-if="source?.source === 'env' && source?.env_var"> <bdi dir="ltr" class="font-mono font-normal">{{ source.env_var }}</bdi></template>
    </span>
</template>

<script setup>
import { computed } from 'vue';
import { SOURCES } from '@/utils/waSettings.js';

// مصدر قيمة واحدة من WaBotSetting::sources(): env | db | default | unset، بلا القيمة نفسها
const props = defineProps({ source: { type: Object, default: null } });

const info = computed(() => SOURCES[props.source?.source]);
const title = computed(() => (props.source?.db_shadowed ? 'للبيئة الأولوية؛ القيمة المحفوظة مهملة' : ''));
</script>
