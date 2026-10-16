<template>
  <p class="text-xs mb-4" data-test="security-scan" :class="scan && scan.critical > 0 ? 'text-red-700 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'">
    🛡️ آخر فحص للصورة:
    <template v-if="scan">
      <bdi dir="ltr">{{ day }}</bdi> —
      <span :class="scan.critical > 0 ? 'font-bold' : ''">حرجة {{ scan.critical }}</span>،
      <span :class="rose ? 'font-bold text-amber-700 dark:text-amber-400' : ''">عالية {{ scan.high }}</span>
      <template v-if="rose"> (كانت {{ scan.previous_high }})</template>
      <template v-if="stale"> · أقدم من أسبوعين: هل يعمل الفحص الأسبوعي؟</template>
    </template>
    <template v-else>لم يُسجَّل فحص بعد.</template>
  </p>
</template>

<script setup>
// سطر في بطاقة «صحة النظام والقناة» (E26.11): التاريخ والأعداد فقط من security:scan-report. التفاصيل على الخادم وحده
import { computed } from 'vue';

const props = defineProps({
    // {scanner, high, critical, at, previous_high} أو null
    scan: { type: Object, default: null },
    // للاختبار: «الآن» ثابت
    now: { type: Number, default: null },
});

const day = computed(() => (props.scan?.at || '').slice(0, 10));
const rose = computed(() => props.scan?.previous_high != null && props.scan.high > props.scan.previous_high);
const stale = computed(() => {
    const at = Date.parse(props.scan?.at || '');
    return Number.isFinite(at) && (props.now ?? Date.now()) - at > 14 * 24 * 3600 * 1000;
});
</script>
