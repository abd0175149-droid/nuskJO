<template>
    <div class="space-y-5">
        <div v-if="!can.cost" class="p-4 rounded-xl border text-sm bg-gray-50 border-gray-200 text-gray-600 dark:bg-gray-800/40 dark:border-gray-700 dark:text-gray-300">الإقفال يحتاج صلاحية «عرض تكاليف العروض».</div>

        <template v-else-if="closing?.settled">
            <div class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">
                🔒 العرض مُقفل منذ <span dir="ltr">{{ closing.approved_at }}</span>. التسوية {{ closing.entry ? 'في القيد ' + closing.entry : 'بلا قيد (لا فروقات)' }}، وأثرها على الربح <b dir="ltr" class="font-mono">{{ money(closing.total) }}</b> د.أ. اختفى العرض من فاتورة العرض ومن البوت.
            </div>
            <RowsTable :rows="closing.rows" readonly/>
        </template>

        <template v-else-if="p">
            <div v-if="!p.is_back" class="p-3 rounded-xl border text-sm bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-200">الرحلة لم تعد بعد. هذه معاينة، والاعتماد بعد تاريخ العودة.</div>
            <div v-if="p.pending" class="p-3 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">في العرض {{ p.pending }} فاتورة معلّقة أو تحت التعديل. لا يُعتمد الإقفال قبل البتّ فيها.</div>

            <section :class="box">
                <h4 :class="h4">البنود المقطوعة <span class="font-normal text-xs text-gray-400">المستحق كاملاً − ما قُيِّد مع المقاعد المباعة</span></h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr :class="th"><th class="px-3 py-2 text-right">البند</th><th class="px-3 py-2 text-right">الوكيل</th><th class="px-3 py-2 text-right">المستحق</th><th class="px-3 py-2 text-right">مقيّد</th><th class="px-3 py-2 text-right">الباقي</th></tr></thead>
                        <tbody>
                            <tr v-for="(r, i) in group" :key="'g' + i" class="border-t border-gray-100 dark:border-gray-700/50">
                                <td class="px-3 py-2"><b>{{ r.label }}</b><div class="text-xs text-gray-500">{{ r.note }}</div></td>
                                <td class="px-3 py-2 text-xs">{{ r.agent }}</td>
                                <td :class="numCls">{{ money(r.expected) }}</td><td :class="numCls">{{ money(r.posted) }}</td><td :class="numCls + ' font-bold'">{{ money(r.diff) }}</td>
                            </tr>
                            <tr v-if="!group.length"><td colspan="5" class="px-3 py-6 text-center text-gray-400">لا بنود مقطوعة.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section :class="box">
                <h4 :class="h4">الأسرّة المحجوزة <span class="font-normal text-xs text-gray-400">عدّل المحجوز إن كان أكبر مما يلزم للمباع</span></h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr :class="th"><th class="px-3 py-2 text-right">الفندق والسعة</th><th class="px-3 py-2 text-right">الوكيل</th><th class="px-3 py-2 text-center">مباع</th><th class="px-3 py-2 text-center">محجوز</th><th class="px-3 py-2 text-right">تكلفة السرير</th><th class="px-3 py-2 text-right">الشاغر</th></tr></thead>
                        <tbody>
                            <tr v-for="r in vacancy" :key="r.key" class="border-t border-gray-100 dark:border-gray-700/50">
                                <td class="px-3 py-2"><b>{{ r.label }}</b><div class="text-xs text-gray-500">الافتراضي {{ r.default }} (غرف كاملة للمباع)</div></td>
                                <td class="px-3 py-2 text-xs">{{ r.agent }}</td>
                                <td class="px-3 py-2 text-center font-mono tabular-nums">{{ r.sold }}</td>
                                <td class="px-3 py-2 text-center">
                                    <label class="sr-only" :for="'allot-' + r.key">الأسرّة المحجوزة لـ {{ r.label }}</label>
                                    <input :id="'allot-' + r.key" v-model.number="inputs.allot[r.key]" type="number" :min="r.sold" dir="ltr" :placeholder="String(r.allot)" class="w-20 px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-mono text-center"/>
                                </td>
                                <td :class="numCls">{{ money(r.bed_cost) }}</td><td :class="numCls + ' font-bold'">{{ money(r.diff) }}</td>
                            </tr>
                            <tr v-if="!vacancy.length"><td colspan="6" class="px-3 py-6 text-center text-gray-400">لا فنادق في فواتير معتمدة.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p :class="hint">تكلفة السرير = تكلفة الفرد لليلة × الليالي، كما في عقد الفندق على العرض.</p>
            </section>

            <section :class="box">
                <h4 :class="h4">فرق فواتير الموردين الفعلية <span class="font-normal text-xs text-gray-400">موجب = رسوم إضافية، سالب = خصم أو استرداد</span></h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <div v-for="r in bill" :key="r.agent_id" class="flex items-center justify-between gap-2 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/60">
                        <label :for="'bill-' + r.agent_id" class="text-sm">{{ r.agent }}</label>
                        <input :id="'bill-' + r.agent_id" v-model.number="inputs.bill[r.agent_id]" type="number" step="0.001" dir="ltr" placeholder="0.000" class="w-28 px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-mono"/>
                    </div>
                </div>
            </section>

            <section :class="box">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 :class="h4">الأثر على حسابات الوكلاء</h4>
                    <button type="button" @click="refresh" :disabled="loading" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 disabled:opacity-50">{{ loading ? '...' : '🔄 إعادة الحساب' }}</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr :class="th"><th class="px-3 py-2 text-right">الحساب</th><th class="px-3 py-2 text-right">مدين</th><th class="px-3 py-2 text-right">دائن</th></tr></thead>
                        <tbody>
                            <tr v-if="p.per_agent.length" class="border-t border-gray-100 dark:border-gray-700/50"><td class="px-3 py-2 font-bold">تكاليف عروض غير مستردة</td><td :class="numCls">{{ p.total > 0 ? money(p.total) : '' }}</td><td :class="numCls">{{ p.total < 0 ? money(-p.total) : '' }}</td></tr>
                            <tr v-for="a in p.per_agent" :key="a.agent_id" class="border-t border-gray-100 dark:border-gray-700/50"><td class="px-3 py-2">{{ a.agent }}</td><td :class="numCls">{{ a.diff < 0 ? money(-a.diff) : '' }}</td><td :class="numCls">{{ a.diff > 0 ? money(a.diff) : '' }}</td></tr>
                            <tr v-if="!p.per_agent.length"><td colspan="3" class="px-3 py-6 text-center text-gray-400">لا فروقات.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-sm">أثر التسوية على ربح العرض: <b dir="ltr" class="font-mono">−{{ money(p.total) }}</b> د.أ</p>
                <p v-if="formError" class="text-sm text-red-600">{{ formError }}</p>
                <button v-if="can.update" type="button" @click="approve" :disabled="p.pending > 0 || !p.is_back || approving" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow disabled:opacity-40 disabled:cursor-not-allowed">اعتماد التسوية وإقفال العرض</button>
            </section>
        </template>
    </div>
</template>

<script setup>
import { computed, reactive, ref, h } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({ offerId: Number, closing: Object, can: Object });

const box = 'rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900';
const h4 = 'text-sm font-bold text-gray-800 dark:text-gray-100';
const hint = 'text-xs text-gray-400 dark:text-gray-500';
const th = 'bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400';
const numCls = 'px-3 py-2 font-mono text-xs tabular-nums';
const money = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const p = ref(props.closing && !props.closing.settled ? props.closing : null);
const inputs = reactive({ allot: {}, bill: {} });
const loading = ref(false);
const approving = ref(false);
const formError = ref('');

const group = computed(() => (p.value?.rows || []).filter((r) => r.type === 'group'));
const vacancy = computed(() => (p.value?.rows || []).filter((r) => r.type === 'vacancy'));
const bill = computed(() => (p.value?.rows || []).filter((r) => r.type === 'bill'));

const clean = () => ({
    allot: Object.fromEntries(Object.entries(inputs.allot).filter(([, v]) => v !== '' && v !== null && v !== undefined)),
    bill: Object.fromEntries(Object.entries(inputs.bill).filter(([, v]) => v !== '' && v !== null && v !== undefined)),
});
const refresh = async () => {
    loading.value = true;
    try {
        const { data } = await axios.post('/offers/' + props.offerId + '/settlement/preview', clean());
        p.value = data;
    } finally {
        loading.value = false;
    }
};
const approve = () => {
    approving.value = true;
    formError.value = '';
    router.post('/offers/' + props.offerId + '/settlement', clean(), {
        preserveScroll: true,
        onError: (e) => { formError.value = Object.values(e).flat().join(' '); },
        onFinish: () => { approving.value = false; },
    });
};

// جدول مختصر لسطور تسوية معتمدة
const RowsTable = (prps) => h('div', { class: 'overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700' }, [
    h('table', { class: 'w-full text-sm' }, [
        h('thead', h('tr', { class: th }, ['السطر', 'الوكيل', 'الفرق'].map((t) => h('th', { class: 'px-3 py-2 text-right' }, t)))),
        h('tbody', (prps.rows || []).filter((r) => r.diff).map((r, i) => h('tr', { key: i, class: 'border-t border-gray-100 dark:border-gray-700/50' }, [
            h('td', { class: 'px-3 py-2' }, r.label),
            h('td', { class: 'px-3 py-2 text-xs' }, r.agent),
            h('td', { class: numCls, dir: 'ltr' }, money(r.diff)),
        ]))),
    ]),
]);
RowsTable.props = ['rows', 'readonly'];
</script>
