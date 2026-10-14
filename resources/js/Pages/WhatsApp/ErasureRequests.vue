<template>
    <AppLayout>
        <template #header>طلبات حذف البيانات</template>
        <div class="space-y-5" dir="rtl">
            <!-- طريقة التنفيذ: سطر الأوامر وحده، بعد التحقق من الهوية -->
            <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-900 dark:text-amber-200 space-y-2">
                <p class="font-bold">طريقة التنفيذ</p>
                <p>بعد «تحقّقت من الهوية» يُنفَّذ الحذف من الخادم: معاينة بالأعداد أولاً، ثم التنفيذ برمز التأكيد الذي تطبعه المعاينة.</p>
                <code dir="ltr" class="block text-xs font-mono bg-white/70 dark:bg-black/30 rounded-lg p-2 overflow-x-auto">docker exec -u www-data nuskjo-app php artisan whatsapp:forget --request=ID</code>
                <p class="text-xs">طلبات التسعير المرتبطة بفاتورة تبقى ضمن السجل المالي، وصف «إيقاف» يبقى إن وُجد.</p>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-3 py-2">#</th><th class="px-3 py-2">الحالة</th><th class="px-3 py-2">القناة</th>
                            <th class="px-3 py-2">المحادثة</th><th class="px-3 py-2">الاستلام</th><th class="px-3 py-2">المهلة</th><th class="px-3 py-2">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in requests" :key="r.id" class="border-t border-gray-100 dark:border-gray-800 align-top">
                            <td class="px-3 py-2 font-mono">{{ r.id }}</td>
                            <td class="px-3 py-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="STATUS[r.status]?.cls">{{ STATUS[r.status]?.label || r.status }}</span>
                                <span v-if="r.verification_method" class="block text-[11px] text-gray-500 mt-0.5">{{ METHOD[r.verification_method] }}</span>
                                <span v-if="r.reason_code" class="block text-[11px] text-gray-500 mt-0.5">{{ REASON[r.reason_code] }}</span>
                            </td>
                            <td class="px-3 py-2">{{ CHANNEL[r.channel] || r.channel }}</td>
                            <td class="px-3 py-2">
                                <Link v-if="r.conversation_id" :href="`/whatsapp/inbox?conv=${r.conversation_id}`" class="text-gold-700 dark:text-gold-400">#{{ r.conversation_id }}</Link>
                                <span v-else>—</span>
                                <bdi v-if="r.phone_tail" dir="ltr" class="block text-[11px] text-gray-500 font-mono">…{{ r.phone_tail }}</bdi>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap" dir="ltr">{{ day(r.received_at) }}</td>
                            <td class="px-3 py-2">
                                <span v-if="r.status === 'done'" class="text-xs text-gray-500">نُفّذ {{ day(r.executed_at) }}</span>
                                <span v-else-if="r.days_left !== null && open(r)" class="px-2 py-0.5 rounded-full text-xs font-bold" :class="slaClass(r)">
                                    {{ r.days_left >= 0 ? `بقي ${r.days_left} يوماً` : `تجاوز بـ ${-r.days_left} يوماً` }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <div v-if="r.status === 'received'" class="flex flex-wrap gap-1.5">
                                    <select v-model="method[r.id]" :aria-label="`طريقة التحقق للطلب ${r.id}`"
                                            class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs dark:text-white">
                                        <option v-for="m in methods" :key="m" :value="m">{{ METHOD[m] }}</option>
                                    </select>
                                    <button type="button" @click="verify(r)" class="px-2.5 py-1 rounded-lg text-xs font-bold border border-green-400 text-green-700 dark:text-green-400">تحقّقت من الهوية</button>
                                </div>
                                <div v-if="r.status === 'received' || r.status === 'verified'" class="flex flex-wrap gap-1.5 mt-1.5">
                                    <select v-model="reason[r.id]" :aria-label="`سبب الرفض للطلب ${r.id}`"
                                            class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs dark:text-white">
                                        <option v-for="x in rejectReasons" :key="x" :value="x">{{ REASON[x] }}</option>
                                    </select>
                                    <button type="button" @click="reject(r)" class="px-2.5 py-1 rounded-lg text-xs border border-red-300 text-red-700 dark:text-red-400">رفض</button>
                                </div>
                                <bdi v-if="r.status === 'verified'" class="block mt-1.5"><code dir="ltr" class="text-[11px] font-mono text-gray-500">--request={{ r.id }}</code></bdi>
                            </td>
                        </tr>
                        <tr v-if="!requests.length"><td colspan="7" class="px-3 py-10 text-center text-gray-400">لا طلبات</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
    requests: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
    rejectReasons: { type: Array, default: () => [] },
    warnDays: Number,
    dueDays: Number,
});

const STATUS = {
    received: { label: 'مستلَم', cls: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' },
    verified: { label: 'متحقَّق — ينتظر التنفيذ', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' },
    running: { label: 'قيد التنفيذ', cls: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300' },
    done: { label: 'نُفّذ', cls: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' },
    rejected: { label: 'مرفوض', cls: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' },
    cancelled: { label: 'أُلغي', cls: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' },
};
const CHANNEL = { whatsapp: 'واتساب', email: 'بريد', staff: 'موظف' };
const METHOD = { same_number_whatsapp: 'من الرقم نفسه على واتساب', email_reply: 'رد بالبريد', in_person: 'حضورياً', id_check: 'فحص الهوية' };
const REASON = { not_owner: 'ليس صاحب البيانات', duplicate: 'مكرر', legal_hold: 'احتفاظ قانوني', withdrawn: 'سحبه العميل', other: 'سبب آخر' };

const method = reactive(Object.fromEntries(props.requests.map((r) => [r.id, props.methods[0]])));
const reason = reactive(Object.fromEntries(props.requests.map((r) => [r.id, props.rejectReasons[0]])));
const open = (r) => ['received', 'verified', 'running'].includes(r.status);
const day = (iso) => (iso ? new Date(iso).toLocaleDateString('ar-JO') : '—');
// أخضر، ثم كهرماني بعد warnDays من الاستلام، ثم أحمر عند المهلة
const slaClass = (r) => {
    if (r.days_left < 0 || r.days_left === 0) return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
    if (r.days_left <= (props.dueDays - props.warnDays)) return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300';
    return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300';
};

const verify = (r) => {
    if (!window.confirm(`تأكيد التحقق من هوية صاحب الطلب #${r.id}؟`)) return;
    router.post(`/whatsapp/erasure-requests/${r.id}/verify`, { verification_method: method[r.id] }, { preserveScroll: true });
};
const reject = (r) => {
    if (!window.confirm(`رفض الطلب #${r.id}؟`)) return;
    router.post(`/whatsapp/erasure-requests/${r.id}/reject`, { reason_code: reason[r.id] }, { preserveScroll: true });
};
</script>
