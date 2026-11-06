<template>
    <AppLayout>
        <template #header>حالة النظام</template>
        <Head :title="title || 'حالة النظام'" />

        <div class="space-y-6 pb-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    للقراءة فقط · آخر بناء: <bdi dir="ltr" data-test="built-at">{{ when(snap.at) }}</bdi> · يتحدّث كل دقيقة
                    <span v-if="error" class="text-red-600 dark:text-red-400 font-bold"> · {{ error }}</span>
                </p>
                <button type="button" data-test="refresh" :disabled="loading" @click="refresh"
                        class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-bold text-gray-700 dark:text-gray-200 hover:border-gold-400 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500">
                    {{ loading ? '⏳ جارٍ التحديث…' : '🔄 حدّث الآن' }}
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <!-- واتساب: بوابة OpenWA والطابور الصادر واستقبال الـ webhook -->
                <section data-test="section-whatsapp" class="md:col-span-2 xl:col-span-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 min-w-0">
                    <header class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                        <h3 class="font-bold text-gray-800 dark:text-gray-100">💬 واتساب</h3>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full border" :class="levelClass(wa.level)">{{ level(wa.level).icon }} {{ level(wa.level).label }}</span>
                    </header>
                    <p v-if="wa.configured === false" class="text-sm text-gray-500 dark:text-gray-400">لا رقم مربوطاً بعد.</p>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 text-sm">
                        <div data-test="gateway" class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 min-w-0 space-y-1">
                            <p class="font-bold text-gray-700 dark:text-gray-200">بوابة OpenWA</p>
                            <p v-if="wa.gateway?.configured === false" class="text-xs text-gray-500 dark:text-gray-400">غير مضبوطة (OPENWA_URL وOPENWA_ADMIN_KEY).</p>
                            <p v-else :class="wa.gateway?.reachable ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400 font-bold'">
                                {{ wa.gateway?.reachable ? '✅ تستجيب' : '⛔ لا تستجيب' }}
                                <bdi v-if="wa.gateway?.error" dir="ltr" class="font-mono text-xs">{{ wa.gateway.error }}</bdi>
                            </p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">الإصدار: <bdi dir="ltr">{{ wa.gateway?.version || '—' }}</bdi> · الناقل: <bdi dir="ltr">{{ wa.transport || '—' }}</bdi></p>
                        </div>
                        <div data-test="outbox" class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 min-w-0 space-y-1">
                            <p class="font-bold text-gray-700 dark:text-gray-200">الطابور الصادر</p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                ينتظر: {{ wa.outbox?.pending ?? 0 }} · مؤجَّل: {{ wa.outbox?.deferred ?? 0 }} · بانتظار الموافقة: {{ wa.outbox?.awaiting_approval ?? 0 }}
                            </p>
                            <p v-if="wa.outbox?.overdue" class="text-xs font-bold text-amber-700 dark:text-amber-400">⚠️ {{ wa.outbox.overdue }} رسالة تأخرت أكثر من 30 دقيقة عن موعدها.</p>
                            <a href="/whatsapp/outbox" class="text-xs text-gold-700 dark:text-gold-400 hover:underline">الطابور</a>
                        </div>
                        <div data-test="webhook" class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 min-w-0 space-y-1">
                            <p class="font-bold text-gray-700 dark:text-gray-200">استقبال الـ webhook</p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                آخر webhook موقّع: <bdi dir="ltr">{{ when(wa.intake?.last_signed_at) }}</bdi>
                                · توقيعات مرفوضة (يومان): {{ wa.intake?.signatures?.day ?? 0 }}
                                · ينتظر يدوياً: {{ wa.intake?.dead_open ?? 0 }}
                            </p>
                        </div>
                    </div>
                </section>

                <StatusCard v-for="card in cards" :key="card.key" :data-test="'section-' + card.key" :title="card.title" :level="sections[card.key]?.level" :rows="card.rows" :note="card.note"/>

                <!-- أرقام واتساب (C054) -->
                <section data-test="section-numbers" class="md:col-span-2 xl:col-span-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 min-w-0">
                    <header class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                        <h3 class="font-bold text-gray-800 dark:text-gray-100">📱 أرقام واتساب</h3>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full border" :class="levelClass(numbers.level)">{{ level(numbers.level).icon }} {{ level(numbers.level).label }}</span>
                    </header>
                    <p v-if="numbers.state === 'none'" class="text-sm text-gray-500 dark:text-gray-400">لا رقم فعّالاً.</p>
                    <div v-if="numbers.numbers?.length" class="overflow-x-auto -mx-1">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500 dark:text-gray-400 text-start">
                                    <th class="px-2 py-1.5 text-start font-medium">الرقم</th>
                                    <th class="px-2 py-1.5 text-start font-medium">الحالة</th>
                                    <th class="px-2 py-1.5 text-start font-medium">جاهز</th>
                                    <th class="px-2 py-1.5 text-start font-medium">آخر فحص ناجح</th>
                                    <th class="px-2 py-1.5 text-start font-medium">آخر وارد</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(n, i) in numbers.numbers" :key="i" data-test="number-row" class="border-t border-gray-100 dark:border-gray-800"
                                    :class="!n.ready ? 'bg-red-50 dark:bg-red-900/10' : (n.paused_until ? 'bg-amber-50 dark:bg-amber-900/10' : '')">
                                    <td class="px-2 py-1.5 whitespace-nowrap dark:text-gray-200"><bdi dir="auto">{{ n.name }}</bdi>
                                        <bdi v-if="n.phone" dir="ltr" class="ms-1 font-mono text-xs text-gray-500">+{{ n.phone }}</bdi></td>
                                    <td class="px-2 py-1.5"><bdi dir="ltr" :class="n.status === 'ready' ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400 font-bold'">{{ n.status || '—' }}</bdi></td>
                                    <td class="px-2 py-1.5 dark:text-gray-200">{{ n.ready ? (n.paused_until ? '⏸️ موقوف مؤقتاً' : '✅') : '⛔' }}<template v-if="n.probe_failures"> · {{ n.probe_failures }} إخفاق</template></td>
                                    <td class="px-2 py-1.5"><bdi dir="ltr" class="dark:text-gray-200">{{ when(n.last_probe_ok_at) }}</bdi></td>
                                    <td class="px-2 py-1.5"><bdi dir="ltr" class="dark:text-gray-200">{{ when(n.last_inbound_at) }}</bdi></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-2">للعلم فقط: الربط والإصلاح وإعادة التشغيل من صفحة <a href="/whatsapp/channels" class="underline">الأرقام</a>.</p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
// صفحة «حالة النظام» (E23): لقطة SystemStatus للقراءة فقط، تتحدّث كل 60 ثانية والصفحة ظاهرة. لا قيمة سرية ولا رقم عميل
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusCard from '@/Components/Ops/StatusCard.vue';
import { when } from '@/utils/waSettings.js';
import { age, BACKUP_ITEMS, level, levelClass, QUEUE_LABELS } from '@/utils/opsStatus.js';

const props = defineProps({
    title: { type: String, default: '' },
    status: { type: Object, required: true },
});

const snap = ref(props.status);
const loading = ref(false);
const error = ref('');
const sections = computed(() => snap.value.sections || {});
const wa = computed(() => sections.value.whatsapp || {});
const numbers = computed(() => sections.value.numbers || {});
const yes = (v) => (v ? 'نعم' : 'لا');

const cards = computed(() => {
    const s = sections.value;
    const queueRows = Object.entries(s.queue?.queues || {}).map(([q, v]) => [
        QUEUE_LABELS[q] || q,
        `${v.available} متاحة · أقدمها ${age(v.oldest_seconds)}${v.stuck ? ` · ${v.stuck} عالقة` : ''}`,
    ]);
    const beatRows = [['المجدول', age(s.beats?.schedule_age)], ...Object.entries(s.beats?.queues || {}).map(([q, a]) => [`العامل (${QUEUE_LABELS[q] || q})`, age(a)])];
    const backupRows = s.backups?.enabled === false
        ? [['النسخ', 'غير منشور (BACKUP_ENABLED)']]
        : Object.entries(s.backups?.items || {}).map(([k, v]) => [BACKUP_ITEMS[k] || k, v.at ? `${when(v.at)} (${v.age_hours} س)` : 'لم يحدث']);
    return [
        { key: 'app', title: '🧩 التطبيق', rows: [['البيئة', s.app?.env], ['APP_DEBUG', yes(s.app?.debug)], ['PHP', s.app?.php], ['Laravel', s.app?.laravel], ['النشر', s.app?.build || 'غير معروف']] },
        { key: 'database', title: '🗄️ قاعدة البيانات', rows: [['زمن الاستعلام', s.database?.latency_ms != null ? `${s.database.latency_ms} ms` : '—'], ['الحجم', s.database?.size_mb != null ? `${s.database.size_mb} MB` : '—'], ['SQLite', s.database?.sqlite || '—'], ['journal_mode', s.database?.journal_mode || '—'], ['busy_timeout', s.database?.busy_timeout != null ? `${s.database.busy_timeout} ms` : '—']] },
        { key: 'disk', title: '💽 القرص', rows: [['التخزين', s.disk?.storage ? `${s.disk.storage.used_pct}% مستعمل · ${s.disk.storage.free_gb} GB حر` : '—'], ['القاعدة', s.disk?.database ? `${s.disk.database.used_pct}% مستعمل · ${s.disk.database.free_gb} GB حر` : '—']] },
        { key: 'queue', title: '📮 الطوابير', rows: [...queueRows, ['فاشلة (24 ساعة)', s.queue?.failed_24h ?? '—'], ['retry_after', s.queue?.retry_after != null ? `${s.queue.retry_after} ث` : '—']] },
        { key: 'beats', title: '💓 النبضات', rows: beatRows, note: s.beats?.codes?.length ? `أعطال /up: ${s.beats.codes.join(', ')}` : '' },
        { key: 'backups', title: '🛟 النسخ الاحتياطي', rows: backupRows },
        { key: 'doctor', title: '🩺 فحص الإعدادات', rows: s.doctor?.at
            ? [['آخر فحص', when(s.doctor.at)], ['النتيجة', `${s.doctor.counts.ok} سليم · ${s.doctor.counts.warn} تحذير · ${s.doctor.counts.error} خطأ`], ...(s.doctor.problems || []).slice(0, 5).map((p) => [p.level === 'error' ? '❌' : '⚠️', p.message])]
            : [['آخر فحص', 'لم يُشغَّل بعد']] },
        { key: 'reverb', title: '📡 Reverb', rows: s.reverb?.enabled ? [['المنفذ', s.reverb.port], ['يستمع', yes(s.reverb.listening)]] : [['البث اللحظي', 'غير مفعّل']] },
    ];
});

async function load(path, method = 'get') {
    loading.value = true;
    try {
        const config = { headers: { Accept: 'application/json' } };
        const { data } = method === 'post' ? await axios.post(path, {}, config) : await axios.get(path, config);
        snap.value = data;
        error.value = '';
    } catch (e) {
        error.value = e?.response?.status === 429 ? 'ستّ مرات في الدقيقة — انتظر قليلاً' : 'تعذّر التحديث';
    } finally {
        loading.value = false;
    }
}
const refresh = () => load('/api/ops/status/refresh', 'post');

let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (typeof document === 'undefined' || document.visibilityState === 'visible') load('/api/ops/status');
    }, 60000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>
