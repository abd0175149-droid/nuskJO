<template>
  <div class="space-y-3">
    <div class="rounded-xl border p-3.5 text-sm flex flex-wrap items-center gap-x-4 gap-y-2"
         :class="status.suspended
           ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800'
           : (status.bot_ready ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800'
                               : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800')">
      <span class="font-bold">
        <template v-if="status.suspended">⛔ الإرسال موقوف — {{ SUSPEND_SOURCES[status.suspended_source] || 'قفل طارئ' }}</template>
        <template v-else-if="status.bot_ready">🟢 البوت يعمل</template>
        <template v-else-if="status.channel_ready">🟡 القناة مهيّأة والبوت مطفأ</template>
        <template v-else>🟡 القناة غير مهيّأة</template>
      </span>
      <span class="text-gray-600 dark:text-gray-400 text-xs hidden sm:inline">
        <template v-if="!status.channel_ready">أضف بيانات Meta ومفتاح الذكاء من صفحة الإعدادات لتشغيل النظام.</template>
        <template v-else-if="!status.bot_ready">المحادثات تعمل والموظفون يردّون؛ فعّل البوت من الإعدادات عند الجهوزية.</template>
        <template v-else>الموظف يمكنه الردّ في أي وقت — وردّه يوقف البوت مؤقتاً تلقائياً.</template>
      </span>
      <a href="/whatsapp/settings" class="text-gold-700 dark:text-gold-400 font-bold text-xs hover:underline ms-auto">⚙️ الإعدادات</a>
    </div>

    <!-- ميتا أوقفت القناة: عام بلا تفاصيل، والتفاصيل في الإعدادات -->
    <div v-if="status.meta_blocked" role="alert"
         class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3.5 text-sm font-bold text-red-700 dark:text-red-300">
      ⛔ القناة متوقفة من جهة ميتا — أبلغ المدير.
    </div>

    <!-- ميتا ترفض الإرسال على مستوى القناة (E18): الوصف والإجراء للمدير، وسطر عام لغيره -->
    <div v-if="status.meta_error" role="alert" data-test="meta-error-bar"
         class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3.5 text-sm font-bold text-red-700 dark:text-red-300 break-words">
      <template v-if="status.meta_error.generic">⛔ تعذّر الإرسال مؤقتاً — أُبلغ المسؤول.</template>
      <template v-else>
        ⛔ ميتا ترفض الإرسال: {{ status.meta_error.message }}<template v-if="status.meta_error.action"> — {{ status.meta_error.action }}</template>
        <a href="/whatsapp/settings#ops" class="ms-1 underline">التفاصيل</a>
      </template>
    </div>

    <!-- وضع التحويل (E12): مزوّد الذكاء متعطّل -->
    <div v-if="llm.state && llm.state !== 'closed'" role="alert"
         class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-3.5 text-sm font-bold text-amber-800 dark:text-amber-300">
      🔌 البوت في وضع التحويل<span v-if="llm.opened_at"> منذ {{ hhmm(llm.opened_at) }}</span>. مزوّد الذكاء متعطّل — ردّوا على العملاء يدوياً.
    </div>

    <div v-if="staleSince" role="status"
         class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-2.5 text-xs text-gray-600 dark:text-gray-300">
      ⚠️ تعذّر التحديث منذ {{ staleSince }} — تحقّق من الاتصال، وستتحدّث القائمة تلقائياً عند عودته.
    </div>

    <div v-if="notice" role="alert"
         class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-3.5 text-sm text-amber-800 dark:text-amber-300 flex items-start gap-3">
      <span class="flex-1">⚠️ {{ notice }}</span>
      <button type="button" @click="$emit('dismiss')" aria-label="إغلاق التنبيه" class="font-bold leading-none hover:text-amber-950 dark:hover:text-amber-100">✕</button>
    </div>
  </div>
</template>

<script setup>
import { SUSPEND_SOURCES } from '@/utils/waSettings.js';

defineProps({
  status: { type: Object, default: () => ({}) },
  llm: { type: Object, default: () => ({ state: 'closed' }) },
  staleSince: { type: String, default: '' },
  notice: { type: String, default: '' },
});
defineEmits(['dismiss']);

const hhmm = (iso) => new Date(iso).toLocaleTimeString('ar-JO', { hour: '2-digit', minute: '2-digit' });
</script>
