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
                <!-- واتساب -->
                <section data-test="section-whatsapp" class="md:col-span-2 xl:col-span-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 min-w-0">
                    <header class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                        <h3 class="font-bold text-gray-800 dark:text-gray-100">💬 قناة واتساب</h3>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full border" :class="levelClass(wa.level)">{{ level(wa.level).icon }} {{ level(wa.level).label }}</span>
                    </header>
                    <p v-if="wa.configured === false" class="text-sm text-gray-500 dark:text-gray-400">القناة غير مهيّأة.</p>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 text-sm">
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 min-w-0 space-y-1">
                            <p class="font-bold text-gray-700 dark:text-gray-200">الإرسال عند ميتا</p>
                            <p v-if="wa.health" class="dark:text-gray-200">{{ wa.health.label }} <bdi dir="ltr" class="font-mono text-xs">{{ wa.health.state }}</bdi></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                الفحص: <bdi dir="ltr" class="font-mono">{{ wa.channel?.can_send_message || '—' }}</bdi>
                                · الجودة: <bdi dir="ltr">{{ wa.channel?.quality_rating || '—' }}</bdi>
                            </p>
                            <p v-if="wa.channel?.number" class="text-xs text-gray-600 dark:text-gray-400">
                                الرقم: <bdi dir="ltr">{{ wa.channel.number.status || '—' }}</bdi> · الاسم: <bdi dir="ltr">{{ wa.channel.number.name_status || '—' }}</bdi>
                                · الإنتاجية: <bdi dir="ltr">{{ wa.channel.number.throughput || '—' }}</bdi>
                            </p>
                            <p class="text-[11px] text-gray-400">آخر فحص: <bdi dir="ltr">{{ when(wa.channel?.checked_at) }}</bdi><template v-if="!wa.monitor"> · المراقب الموسّع مطفأ</template></p>
                        </div>
                        <div data-test="webhook" class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 min-w-0 space-y-1">
                            <p class="font-bold text-gray-700 dark:text-gray-200">الويبهوك عند ميتا</p>
                            <template v-if="wa.webhook">
                                <p :class="wa.webhook.ok === false ? 'text-red-700 dark:text-red-400 font-bold' : 'text-green-700 dark:text-green-400'">
                                    {{ wa.webhook.ok === false ? '⚠️ انحراف' : (wa.webhook.ok ? '✅ يشير إلى نُسك' : 'لم يُقرأ بعد') }}
                                </p>
                                <ul v-if="wa.webhook.issues?.length" class="text-xs text-red-700 dark:text-red-400 list-disc pr-4">
                                    <li v-for="i in wa.webhook.issues" :key="i">{{ issueLabel(i) }}</li>
                                </ul>
                                <p v-if="wa.webhook.callback" class="text-xs text-gray-600 dark:text-gray-400">المضيف: <bdi dir="ltr" class="font-mono break-all">{{ wa.webhook.callback.host }}</bdi></p>
                                <p v-if="Object.keys(wa.webhook.field_versions || {}).length" class="text-[11px] text-gray-500 dark:text-gray-400 break-words">
                                    الحقول: <bdi dir="ltr" class="font-mono">{{ Object.entries(wa.webhook.field_versions).map(([f, v]) => v ? `${f}@${v}` : f).join(' · ') }}</bdi>
                                </p>
                                <p v-if="wa.webhook.configuration_available === false" class="text-[11px] text-gray-400">webhook_configuration غير متاح على هذا الإصدار.</p>
                            </template>
                            <p v-else class="text-xs text-gray-500 dark:text-gray-400">لم يُقرأ بعد (المراقب الدوري).</p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                آخر ويبهوك موقّع: <bdi dir="ltr">{{ when(wa.intake?.last_signed_at) }}</bdi>
                                · توقيعات مرفوضة (يومان): {{ wa.intake?.signatures?.day ?? 0 }}
                                · ينتظر يدوياً: {{ wa.intake?.dead_open ?? 0 }}
                            </p>
                        </div>
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 min-w-0 space-y-1">
                            <p class="font-bold text-gray-700 dark:text-gray-200">الرمز والإصدار</p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                رمز ميتا: {{ CREDENTIAL_STATES[wa.credentials?.state] || 'لم يُفحص' }}
                                <template v-if="wa.credentials?.never_expires"> · لا ينتهي</template>
                                <template v-else-if="wa.credentials?.days_left != null"> · ينتهي بعد {{ wa.credentials.days_left }} يوماً</template>
                                <template v-if="wa.credentials?.missing_scopes?.length"> · ناقص: <bdi dir="ltr" class="font-mono">{{ wa.credentials.missing_scopes.join(', ') }}</bdi></template>
                            </p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                Graph: <bdi dir="ltr">{{ wa.graph?.requests?.version || '—' }}</bdi>
                                <template v-if="wa.graph?.requests?.sunset"> حتى <bdi dir="ltr">{{ wa.graph.requests.sunset }}</bdi></template>
                                · الويبهوك: <bdi dir="ltr">{{ wa.graph?.webhook?.version || 'غير مضبوط' }}</bdi>
                            </p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">الناقل: <bdi dir="ltr">{{ wa.transport || '—' }}</bdi> · appsecret_proof: {{ wa.graph?.appsecret_proof ? 'مفعّل' : 'مطفأ' }}</p>
                        </div>
                    </div>
                </section>

                <StatusCard v-for="card in cards" :key="card.key" :data-test="'section-' + card.key" :title="card.title" :level="sections[card.key]?.level" :rows="card.rows" :note="card.note"/>

                <!-- أرقام الحساب (C054) -->
                <section data-test="section-numbers" class="md:col-span-2 xl:col-span-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 min-w-0">
                    <header class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                        <h3 class="font-bold text-gray-800 dark:text-gray-100">📱 أرقام الحساب</h3>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full border" :class="levelClass(numbers.level)">{{ level(numbers.level).icon }} {{ level(numbers.level).label }}</span>
                    </header>
                    <p v-if="numbers.state === 'no_waba'" class="text-sm text-gray-600 dark:text-gray-300">أدخل WA_WABA_ID أو انتظر اكتشافه من الفحص الدوري.</p>
                    <p v-else-if="numbers.state === 'not_configured'" class="text-sm text-gray-500 dark:text-gray-400">القناة غير مهيّأة أو فحوص ميتا مطفأة.</p>
                    <p v-else-if="numbers.state && numbers.state !== 'ok'" class="text-sm text-amber-700 dark:text-amber-400">تعذّرت قراءة الأرقام من ميتا الآن.</p>
                    <div v-if="numbers.numbers?.length" class="overflow-x-auto -mx-1">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500 dark:text-gray-400 text-start">
                                    <th class="px-2 py-1.5 text-start font-medium">الرقم</th>
                                    <th class="px-2 py-1.5 text-start font-medium">الاسم الموثّق</th>
                                    <th class="px-2 py-1.5 text-start font-medium">الحالة</th>
                                    <th class="px-2 py-1.5 text-start font-medium">الجودة</th>
                                    <th class="px-2 py-1.5 text-start font-medium">الاسم</th>
                                    <th class="px-2 py-1.5 text-start font-medium">التحقق</th>
                                    <th class="px-2 py-1.5 text-start font-medium">الإنتاجية</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(n, i) in numbers.numbers" :key="i" data-test="number-row" class="border-t border-gray-100 dark:border-gray-800"
                                    :class="!n.ours && (n.status !== 'CONNECTED' || n.quality_rating === 'RED') ? 'bg-amber-50 dark:bg-amber-900/10' : ''">
                                    <td class="px-2 py-1.5 whitespace-nowrap"><bdi dir="ltr" class="font-mono">{{ n.display || '—' }}</bdi>
                                        <span v-if="n.ours" class="ms-1 text-[11px] font-bold px-1.5 py-0.5 rounded-full bg-gold-100 text-gold-800 dark:bg-gold-900/30 dark:text-gold-300">رقم نُسك</span></td>
                                    <td class="px-2 py-1.5 dark:text-gray-200">{{ n.verified_name || '—' }}</td>
                                    <td class="px-2 py-1.5"><bdi dir="ltr" :class="n.status === 'CONNECTED' ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400 font-bold'">{{ n.status || '—' }}</bdi></td>
                                    <td class="px-2 py-1.5"><bdi dir="ltr" :class="n.quality_rating === 'RED' ? 'text-red-700 dark:text-red-400 font-bold' : 'dark:text-gray-200'">{{ n.quality_rating || '—' }}</bdi></td>
                                    <td class="px-2 py-1.5"><bdi dir="ltr" class="dark:text-gray-200">{{ n.name_status || '—' }}</bdi></td>
                                    <td class="px-2 py-1.5"><bdi dir="ltr" class="dark:text-gray-200">{{ n.code_verification_status || '—' }}</bdi></td>
                                    <td class="px-2 py-1.5"><bdi dir="ltr" class="dark:text-gray-200">{{ n.throughput || '—' }}</bdi></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-2">للعلم فقط: إضافة رقم أو حذفه تتم من WhatsApp Manager. تُقرأ من ميتا مرة في الساعة<template v-if="numbers.at"> (آخرها <bdi dir="ltr">{{ when(numbers.at) }}</bdi>)</template>.</p>
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
import { age, BACKUP_ITEMS, CREDENTIAL_STATES, issueLabel, level, levelClass, QUEUE_LABELS } from '@/utils/opsStatus.js';

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
