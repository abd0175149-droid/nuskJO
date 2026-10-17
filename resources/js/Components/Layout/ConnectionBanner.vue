<template>
  <!-- شريط عام واحد (E28): الخادم غير متاح، أو تحديث متاح بعد النشر. حالة المقبس للصندوق في InboxStatusBar -->
  <div v-if="state.serverDown || state.updateAvailable"
       class="fixed inset-x-0 top-[var(--app-header)] z-40 flex justify-center px-3 pt-2 pointer-events-none">
    <div v-if="state.serverDown" role="status" aria-live="polite" data-test="server-down"
         class="pointer-events-auto max-w-full flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900 px-4 py-2 text-sm font-bold text-amber-900 dark:text-amber-100 shadow-lg">
      <span>⏳ النظام يُعاد تشغيله أو غير متاح — سنحاول تلقائياً</span>
      <button type="button" @click="probeNow" class="min-h-9 text-xs underline hover:no-underline">أعد المحاولة الآن</button>
    </div>
    <div v-else role="status" aria-live="polite" data-test="update-available"
         class="pointer-events-auto max-w-full flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-blue-300 dark:border-blue-700 bg-blue-50 dark:bg-blue-900 px-4 py-2 text-sm font-bold text-blue-900 dark:text-blue-100 shadow-lg">
      <span>🔄 تحديث متاح — احفظ ثم أعد التحميل</span>
      <button type="button" @click="reload" class="min-h-9 text-xs underline hover:no-underline">إعادة التحميل</button>
    </div>
  </div>
</template>

<script setup>
import { useConnectionStatus } from '@/composables/useConnectionStatus.js';

const { state, probeNow } = useConnectionStatus();
const reload = () => window.location.reload();
</script>
