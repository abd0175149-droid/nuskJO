<template>
    <div class="min-w-[120px] space-y-1">
        <div class="flex h-2 rounded-full overflow-hidden bg-gray-100 dark:bg-gray-700" role="img" :aria-label="`${approved} معتمد، ${pending} معلّق، ${available} متاح من ${total}`">
            <span class="block h-full bg-gold-500" :style="{ width: pct(approved) }"></span>
            <span class="block h-full bg-amber-300 dark:bg-amber-500/70" :style="{ width: pct(pending) }"></span>
        </div>
        <div class="text-xs text-gray-600 dark:text-gray-300">
            <b class="font-mono tabular-nums" :class="available <= 0 ? 'text-red-600 dark:text-red-400' : ''">{{ available }}</b> متاح من <span class="font-mono tabular-nums">{{ total }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

// المتاح يُحسب من الفواتير: الكلي − (المعلّقة + تحت التعديل + المعتمدة)
const props = defineProps({ total: Number, approved: { type: Number, default: 0 }, booked: { type: Number, default: 0 } });
const pending = computed(() => Math.max(0, props.booked - props.approved));
const available = computed(() => props.total - props.booked);
const pct = (n) => (props.total ? Math.min(100, (n / props.total) * 100) : 0) + '%';
</script>
