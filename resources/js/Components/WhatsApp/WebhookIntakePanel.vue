<template>
    <div id="webhook" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6 scroll-mt-20">
        <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">📥 استقبال الويبهوك</h3>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs font-bold px-2.5 py-1 rounded-full" :class="summary.log?.on ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'">
                    الحفظ قبل الإقرار: {{ summary.log?.on ? (summary.log.mode === 'failures' ? 'عند الخطأ' : 'يعمل') : 'مطفأ' }}
                </span>
                <span class="text-xs font-bold px-2.5 py-1 rounded-full" :class="summary.fence?.mode === 'enforce' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'">
                    السياج: {{ summary.fence?.mode === 'enforce' ? 'فرض' : 'تسجيل فقط' }}
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

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 min-w-0">
                <p class="font-bold text-gray-700 dark:text-gray-200 mb-1">المرفوض بالسياج (يومان)</p>
                <ul class="space-y-0.5 text-xs">
                    <li v-for="(c, reason) in summary.fence?.counts || {}" :key="reason" class="flex items-center justify-between gap-2">
                        <span class="text-gray-600 dark:text-gray-300">{{ reasonLabel(reason) }}</span>
                        <span class="font-mono" dir="ltr">
                            <span :class="c.rejected ? 'text-red-700 dark:text-red-400 font-bold' : 'text-gray-500'">{{ c.rejected }}</span>
                            <span class="text-gray-400"> / {{ c.would }}</span>
                        </span>
                    </li>
                </ul>
                <p class="text-[11px] text-gray-400 mt-1">مرفوض فعلاً / كان سيُرفض (وضع التسجيل). لا يُخزَّن محتوى المرفوض.</p>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 min-w-0">
                <p class="font-bold text-gray-700 dark:text-gray-200 mb-1">التوقيع والحقول</p>
                <p class="text-xs text-gray-600 dark:text-gray-300">توقيعات مرفوضة: آخر ساعة <b>{{ summary.signatures?.hour || 0 }}</b> · يومان <b>{{ summary.signatures?.day || 0 }}</b></p>
                <div class="text-xs text-gray-600 dark:text-gray-300 mt-1 flex flex-wrap items-center gap-1">
                    <span>حقول غير معالجة:</span>
                    <template v-if="unknownFields.length">
                        <bdi v-for="[name, n] in unknownFields" :key="name" dir="ltr" class="font-mono rounded bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 break-all">{{ name }} ×{{ n }}</bdi>
                    </template>
                    <span v-else class="text-gray-400">لا شيء</span>
                </div>
            </div>
        </div>
        <p class="mt-2 text-[11px] text-gray-400">
            الحد {{ mb }}MB · الجسم الفاشل يُحفظ مشفّراً {{ summary.limits?.body_hours }} ساعة حداً أقصى، والأثر الوصفي {{ summary.limits?.meta_days }} يوماً · {{ summary.limits?.max_attempts }} محاولات ثم «يحتاج معالجة يدوية».
        </p>
    </div>
</template>

<script setup>
// بطاقة «استقبال الويبهوك» (E20.9): أعداد وأسماء حقول وأعمار فقط، بلا جسم ولا رقم ولا نص
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

const REASONS = {
    waba_mismatch: 'حساب أعمال آخر',
    pnid_mismatch: 'رقم آخر',
    pnid_missing: 'بلا معرّف رقم',
    channel_unconfigured: 'القناة غير مضبوطة',
};
const reasonLabel = (r) => REASONS[r] || r;

const WARNINGS = {
    dead: { text: 'إشعارات لم تُعالج بعد كل المحاولات — راجع الصندوق يدوياً قبل أن تُمحى، ثم أعد ما يلزم بالأمر', cmd: 'whatsapp:replay-webhooks --id=… --force' },
    rejected: { text: 'رُفضت إشعارات لرقم أو حساب آخر — إن كان المعرّف المضبوط خاطئاً فقد تضيع رسائل العملاء.' },
    retention: { text: 'جسم إشعار محفوظ أطول من مدة الاحتفاظ — راجع المجدول وشغّل', cmd: 'whatsapp:webhook-events-prune' },
    waba_mismatch: { text: 'معرّف حساب الأعمال المضبوط لا يطابق المكتشف من ميتا — صحّحه قبل تفعيل الفرض.' },
    signatures: { text: 'توقيعات مرفوضة كثيرة — قد يكون App Secret لا يطابق تطبيق ميتا بعد تدوير.' },
};
const warning = (w) => WARNINGS[w] || { text: w };
const warnings = computed(() => props.summary.warnings || []);
const unknownFields = computed(() => Object.entries(props.summary.unknown_fields || {}));
const mb = computed(() => Math.round(((props.summary.limits?.max_bytes || 0) / 1048576) * 10) / 10);
</script>
