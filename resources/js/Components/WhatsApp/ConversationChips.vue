<template>
  <!-- تصنيفات المحادثة وشارات النظام في سطر القائمة (E29): النص من الخادم، والألوان من خريطة ثابتة، والمالية بلا مبالغ -->
  <span v-if="labels.length || badges.length" class="flex items-center gap-1 mt-1 flex-wrap" data-test="conversation-chips">
    <span v-for="l in labels" :key="'l' + l.id" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] leading-4 ring-1"
          :class="labelClass(l.color, l.active)" :title="l.active === false ? 'تصنيف معطّل' : 'تصنيف داخلي'" data-test="label-chip"><bdi dir="auto">{{ l.name }}</bdi></span>
    <span v-for="b in badges" :key="'b' + b.key" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] leading-4 bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
          :title="b.label" data-test="badge"><span aria-hidden="true">{{ BADGE_ICONS[b.key] || '•' }}</span>{{ b.label }}</span>
  </span>
</template>

<script setup>
import { BADGE_ICONS, labelClass } from '@/composables/whatsapp/useLabels.js';

defineProps({
  labels: { type: Array, default: () => [] },
  badges: { type: Array, default: () => [] },
});
</script>
