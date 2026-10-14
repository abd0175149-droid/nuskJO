<template>
    <div id="media" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6 scroll-mt-20">
        <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">🗂️ أرشيف المرفقات</h3>
            <span class="text-xs font-bold px-2.5 py-1 rounded-full"
                  :class="summary.enabled ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'">
                {{ summary.enabled ? 'يعمل' : 'مطفأ' }}
            </span>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            حفظ صور العملاء ووثائقهم على الخادم وعرضها لمن يملك صلاحية «عرض وتنزيل مرفقات العملاء». يُفعَّل من لوحة الميزات في هذه الصفحة بعد اعتماد نصّي الخصوصية والحذف.
        </p>
        <div class="space-y-1">
            <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                <span>المساحة المستعملة</span>
                <span dir="ltr">{{ summary.used_mb }} / {{ summary.quota_mb }} MB ({{ summary.pct }}%)</span>
            </div>
            <div class="h-2 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden" role="progressbar" :aria-valuenow="summary.pct" aria-valuemin="0" aria-valuemax="100" aria-label="المساحة المستعملة من سقف الأرشيف">
                <div class="h-full rounded-full" :class="summary.pct >= 100 ? 'bg-red-500' : summary.pct >= 80 ? 'bg-amber-500' : 'bg-gold-500'" :style="{ width: Math.min(summary.pct, 100) + '%' }"></div>
            </div>
        </div>
        <p v-if="summary.paused" class="mt-2 text-xs text-red-700 dark:text-red-400">⛔ الأرشفة الجديدة متوقفة: بلغ الأرشيف سقفه (WA_MEDIA_QUOTA_MB).</p>
        <dl class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
            <div v-for="row in rows" :key="row.key" class="rounded-lg bg-gray-50 dark:bg-gray-800/60 px-2 py-2">
                <dt class="text-[11px] text-gray-500 dark:text-gray-400">{{ row.label }}</dt>
                <dd class="text-base font-bold text-gray-800 dark:text-gray-100">{{ summary.week?.[row.key] || 0 }}</dd>
            </div>
        </dl>
        <p class="mt-2 text-[11px] text-gray-400">آخر 7 أيام · الاحتفاظ {{ summary.retention_days }} يوماً · البصمة: {{ summary.sha_mode === 'enforce' ? 'إلزامية' : 'تسجيل فقط' }} · إشعار العميل: {{ summary.consent_prompt ? 'مفعّل' : 'مطفأ' }}</p>
    </div>
</template>

<script setup>
// بطاقة «أرشيف المرفقات» (E14.12): أعداد ومساحة فقط، بلا أسماء ولا روابط
defineProps({ summary: { type: Object, required: true } });

const rows = [
    { key: 'stored', label: 'حُفظ' },
    { key: 'too_large', label: 'أكبر من الحد' },
    { key: 'failed', label: 'تعذّر' },
    { key: 'rejected', label: 'نوع مرفوض' },
];
</script>
