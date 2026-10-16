<template>
  <p v-if="line" class="text-xs mb-4" data-test="integrations-health" :class="alarm ? 'text-red-700 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'">
    🔌 التكاملات:
    <template v-if="!line.enabled">مطفأة (العلم integration_outbox)</template>
    <template v-else>
      معلّق <span :class="line.pending > 0 ? 'font-bold' : ''">{{ line.pending }}</span><template v-if="line.oldest_pending_minutes !== null"> (أقدمه {{ age }})</template>
      · فاشل خلال 24 ساعة <span :class="line.dead_24h > 0 ? 'font-bold' : ''">{{ line.dead_24h }}</span>
      · نشطة {{ line.active }} من {{ line.endpoints }}<template v-if="line.paused > 0"> · <span class="font-bold">موقوفة آلياً {{ line.paused }}</span></template>
    </template>
  </p>
</template>

<script setup>
// سطر في بطاقة «صحة النظام والقناة» (E40.6): أعداد من OpsHealth::snapshot().integrations، أو لا شيء قبل الحاجة
import { computed } from 'vue';

const props = defineProps({
    // {enabled, endpoints, active, paused, pending, oldest_pending_minutes, dead_24h} أو null
    line: { type: Object, default: null },
});

const alarm = computed(() => !!props.line?.enabled && (props.line.dead_24h > 0 || props.line.paused > 0 || (props.line.oldest_pending_minutes ?? 0) > 60));
const age = computed(() => {
    const m = props.line?.oldest_pending_minutes ?? 0;
    return m >= 60 ? `${Math.floor(m / 60)} س` : `${m} د`;
});
</script>
