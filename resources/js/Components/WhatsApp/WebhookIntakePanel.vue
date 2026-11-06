<template>
    <div id="webhook" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6 scroll-mt-20">
        <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">📥 استقبال webhook البوابة</h3>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs font-bold px-2.5 py-1 rounded-full" :class="summary.log?.on ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'">
                    الحفظ قبل الإقرار: {{ summary.log?.on ? (summary.log.mode === 'failures' ? 'عند الخطأ' : 'يعمل') : 'مطفأ' }}
                </span>
            </div>
        </div>

        <ul v-if="warnings.length" class="mb-3 space-y-1" role="alert">
            <li v-for="w in warnings" :key="w" class="rounded-lg border border-red-200 dark:border-red-900/60 bg-red-50 dark:bg-red-900/20 px-3 py-2 text-xs font-bold text-red-700 dark:text-red-300">
                ⚠️ {{ warning(w).text }}
                <bdi v-if="warning(w).cmd" dir="ltr" class="font-mono font-normal break-all">{{ warning(w).cmd }}</bdi>
            </li>
        </ul>

        <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 text-center">
            <div v-for="row in rows" :key="row.key" class="rounded-lg bg-gray-50 dark:bg-gray-800/60 px-2 py-2 min-w-0">
                <dt class="text-[11px] text-gray-500 dark:text-gray-400">{{ row.label }}</dt>
                <dd class="text-base font-bold" :class="row.alarm && (summary.last24?.[row.key] || 0) > 0 ? 'text-red-700 dark:text-red-400' : 'text-gray-800 dark:text-gray-100'">
                    {{ summary.last24?.[row.key] || 0 }}
                </dd>
            </div>
        </dl>
        <p class="mt-1 text-[11px] text-gray-400">آخر 24 ساعة · ينتظر المعالجة اليدوية: {{ summary.dead_open || 0 }} · أقدم جسم محفوظ: {{ summary.oldest_body_hours == null ? '—' : summary.oldest_body_hours + ' ساعة' }}</p>

        <p class="mt-3 text-xs text-gray-600 dark:text-gray-300">توقيعات مرفوضة: آخر ساعة <b>{{ summary.signatures?.hour || 0 }}</b> · يومان <b>{{ summary.signatures?.day || 0 }}</b></p>
        <p class="mt-2 text-[11px] text-gray-400">
            الحد {{ mb }}MB · الجسم الفاشل يُحفظ مشفّراً {{ summary.limits?.body_hours }} ساعة حداً أقصى، والأثر الوصفي {{ summary.limits?.meta_days }} يوماً · {{ summary.limits?.max_attempts }} محاولات ثم «يحتاج معالجة يدوية».
        </p>
    </div>
</template>

<script setup>
// بطاقة «استقبال webhook البوابة» (E20.9): أعداد وأعمار فقط، بلا جسم ولا رقم ولا نص
import { computed } from 'vue';

const props = defineProps({ summary: { type: Object, required: true } });

const rows = [
    { key: 'processed', label: 'عولج' },
    { key: 'failed', label: 'ينتظر الإعادة', alarm: true },
    { key: 'dead', label: 'تعذّر', alarm: true },
    { key: 'malformed', label: 'معطوب' },
    { key: 'rejected', label: 'مرفوض', alarm: true },
    { key: 'pending', label: 'قيد المعالجة' },
];

const WARNINGS = {
    dead: { text: 'إشعارات لم تُعالج بعد كل المحاولات — راجع الصندوق يدوياً قبل أن تُمحى، ثم أعد ما يلزم بالأمر', cmd: 'whatsapp:replay-webhooks --id=… --force' },
    rejected: { text: 'رُفضت إشعارات من البوابة — راجع توقيع الرقم أو «إصلاحه» من صفحة الأرقام كي لا تضيع رسائل العملاء.' },
    retention: { text: 'جسم إشعار محفوظ أطول من مدة الاحتفاظ — راجع المجدول وشغّل', cmd: 'whatsapp:webhook-events-prune' },
    signatures: { text: 'توقيعات مرفوضة كثيرة — قد يكون سر توقيع الرقم لا يطابق البوابة؛ «إصلاح» الرقم يعيده.' },
};
const warning = (w) => WARNINGS[w] || { text: w };
const warnings = computed(() => props.summary.warnings || []);
const mb = computed(() => Math.round(((props.summary.limits?.max_bytes || 0) / 1048576) * 10) / 10);
</script>
