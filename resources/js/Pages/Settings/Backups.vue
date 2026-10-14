<template>
    <AppLayout>
        <template #header>النسخ الاحتياطي</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">⚠️ {{ $page.props.flash.error }}</div>

            <!-- مراجعة ما بعد الاستعادة -->
            <div v-if="status.review" class="rounded-xl border-2 border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-5 space-y-4">
                <div>
                    <p class="font-bold text-red-700 dark:text-red-300">🔴 مراجعة ما بعد الاستعادة معلّقة</p>
                    <p class="mt-1 text-sm text-red-700 dark:text-red-300">
                        استُعيدت النسخة <span class="font-mono inline-block" dir="ltr">{{ status.review.snapshot }}</span>
                        <template v-if="status.review.restored_at"> في {{ formatDate(status.review.restored_at) }}</template>.
                        البوت مطفأ والإرسال مقفل حتى تعتمد المراجعة، ثم ترفع القفل من إعدادات البوت.
                    </p>
                </div>
                <form @submit.prevent="acknowledge" class="space-y-2">
                    <label v-for="(label, key) in checklist" :key="key" class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-100">
                        <input v-model="ackForm.checked" type="checkbox" :value="key" :disabled="!can.ack" class="mt-1 w-4 h-4 rounded text-red-600"/>
                        <span>{{ label }}</span>
                    </label>
                    <p v-if="ackForm.errors.checked" class="text-xs font-bold text-red-600">{{ ackForm.errors.checked }}</p>
                    <button v-if="can.ack" type="submit" :disabled="!allChecked || ackForm.processing"
                            class="mt-2 px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-red-600 disabled:opacity-40">
                        اعتماد المراجعة
                    </button>
                    <p v-else class="text-xs text-gray-600 dark:text-gray-400">الاعتماد لمن يملك صلاحية «اعتماد مراجعة ما بعد الاستعادة».</p>
                </form>
            </div>

            <div v-if="!status.enabled" class="p-4 rounded-xl border text-sm bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-300">
                النسخ اليومي المجدول غير مفعّل بعد (BACKUP_ENABLED). النسخ اليدوي يعمل.
            </div>

            <!-- الحالة -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div v-for="card in cards" :key="card.key" class="rounded-xl border p-4 bg-white dark:bg-gray-900"
                     :class="borderFor(card.state)">
                    <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                        <span class="inline-block w-2.5 h-2.5 rounded-full" :class="dotFor(card.state)"></span>{{ card.label }}
                    </p>
                    <p class="mt-2 text-lg font-bold text-gray-900 dark:text-white">{{ card.value }}</p>
                    <p v-if="card.detail" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ card.detail }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    لا تنزيل ولا استعادة من هذه الصفحة عن قصد: الاستعادة بسكربت على الخادم، وتنتهي بمراجعة هنا.
                </p>
                <button v-if="can.run" @click="runNow" :disabled="running"
                        class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
                    🛟 نسخة الآن
                </button>
            </div>

            <!-- آخر التشغيلات -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                <th class="px-4 py-3 text-right font-bold">الوقت</th>
                                <th class="px-4 py-3 text-right font-bold">السبب</th>
                                <th class="px-4 py-3 text-right font-bold">الحالة</th>
                                <th class="px-4 py-3 text-right font-bold">الحجم</th>
                                <th class="px-4 py-3 text-right font-bold">السلامة</th>
                                <th class="px-4 py-3 text-right font-bold">مشفّرة على المضيف</th>
                                <th class="px-4 py-3 text-right font-bold">ملاحظة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="run in runs" :key="run.id" class="border-t border-gray-100 dark:border-gray-700/50">
                                <td class="px-4 py-3 text-xs whitespace-nowrap text-gray-700 dark:text-gray-300">{{ formatDate(run.started_at) }}</td>
                                <td class="px-4 py-3 text-xs">{{ reasonLabels[run.reason] || run.reason }}</td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(run.status)">{{ statusLabels[run.status] || run.status }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs" dir="ltr">{{ formatBytes((run.db_bytes || 0) + (run.media_bytes || 0)) }}</td>
                                <td class="px-4 py-3 text-xs">{{ run.integrity === 'ok' ? '✅' : (run.integrity || '—') }}</td>
                                <td class="px-4 py-3 text-xs">{{ run.collected_at ? '✅ ' + formatDate(run.collected_at) : '—' }}</td>
                                <td class="px-4 py-3 text-xs text-red-600 dark:text-red-400 max-w-xs truncate" :title="run.error">{{ run.error || '' }}</td>
                            </tr>
                            <tr v-if="!runs.length">
                                <td colspan="7" class="px-5 py-12 text-center text-gray-400">لا توجد نسخ بعد</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
    status: Object,
    runs: Array,
    checklist: Object,
    can: Object,
});

const reasonLabels = {
    scheduled: 'يومية', manual: 'يدوية', 'pre-migrate': 'قبل تحديث', 'pre-reset': 'قبل تفريغ',
    'pre-restore': 'قبل استعادة', drill: 'تمرين',
};
const statusLabels = { ok: 'ناجحة', failed: 'فشلت', running: 'جارية' };

const statusClass = (s) => ({
    ok: 'bg-green-100 text-green-700', failed: 'bg-red-100 text-red-700', running: 'bg-blue-100 text-blue-700',
}[s] || 'bg-gray-100 text-gray-600');

const dotFor = (state) => ({ ok: 'bg-green-500', warn: 'bg-amber-500', bad: 'bg-red-500' }[state] || 'bg-gray-400');
const borderFor = (state) => ({
    ok: 'border-green-200 dark:border-green-900', warn: 'border-amber-300 dark:border-amber-800', bad: 'border-red-300 dark:border-red-800',
}[state] || 'border-gray-200 dark:border-gray-700');

const formatBytes = (n) => {
    if (!n) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
};

const formatDate = (iso) => iso
    ? new Date(iso).toLocaleString('ar-JO', { timeZone: 'Asia/Amman', dateStyle: 'short', timeStyle: 'short' })
    : '—';

const ageLabel = (hours) => {
    if (hours === null || hours === undefined) return 'لم يحدث بعد';
    if (hours < 1) return 'منذ أقل من ساعة';
    if (hours < 48) return `قبل ${hours} ساعة`;
    return `قبل ${Math.floor(hours / 24)} يوماً`;
};

const cards = computed(() => {
    const it = props.status.items;
    return [
        {
            key: 'snapshot', label: 'آخر نسخة ناجحة', state: it.snapshot.state, value: ageLabel(it.snapshot.age_hours),
            detail: it.snapshot.db_bytes ? `القاعدة ${formatBytes(it.snapshot.db_bytes)} · الوسائط ${formatBytes(it.snapshot.media_bytes)}` : null,
        },
        { key: 'collected', label: 'آخر سحب مشفّر إلى المضيف', state: it.collected.state, value: ageLabel(it.collected.age_hours) },
        { key: 'pulled', label: 'آخر نقل إلى جهاز المالك', state: it.pulled.state, value: ageLabel(it.pulled.age_hours) },
        {
            key: 'drill', label: 'آخر تمرين استعادة', state: it.drill.state, value: ageLabel(it.drill.age_hours),
            detail: it.drill.result ? (it.drill.result === 'ok' ? 'نجح' : 'فشل') : null,
        },
        {
            key: 'disk', label: 'القرص', state: it.disk.state,
            value: it.disk.used_pct !== null ? `${it.disk.used_pct}%` : '—',
            detail: `مجلد النسخ ${it.disk.spool_mb} MB`,
        },
    ];
});

const running = ref(false);
const runNow = () => {
    running.value = true;
    router.post('/settings/backups/run', {}, { preserveScroll: true, onFinish: () => { running.value = false; } });
};

const ackForm = useForm({ checked: [] });
const allChecked = computed(() => Object.keys(props.checklist).every((k) => ackForm.checked.includes(k)));
const acknowledge = () => ackForm.post('/settings/backups/ack-restore', { preserveScroll: true });
</script>
