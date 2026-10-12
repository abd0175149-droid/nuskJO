<template>
    <div class="space-y-5">
        <div v-if="!can.cost" class="p-4 rounded-xl border text-sm bg-gray-50 border-gray-200 text-gray-600 dark:bg-gray-800/40 dark:border-gray-700 dark:text-gray-300">الإقفال يحتاج صلاحية «عرض تكاليف العروض».</div>

        <template v-else-if="closing?.settled">
            <div class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">
                🔒 العرض مُقفل منذ <span dir="ltr">{{ closing.approved_at }}</span>. المصاريف المشتركة والفروقات <b dir="ltr" class="font-mono">{{ money(closing.total) }}</b> د.أ سُجّلت مصروفاً في «مصاريف الرحلات المشتركة» {{ closing.entry ? 'بالقيد ' + closing.entry : 'بلا قيد (لا مبالغ)' }}، وربح العرض بقي كما هو. اختفى العرض من فاتورة العرض ومن البوت.
            </div>
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead><tr :class="th"><th class="px-3 py-2 text-right">السطر</th><th class="px-3 py-2 text-right">الوكيل</th><th class="px-3 py-2 text-right">المبلغ د.أ</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in (closing.rows || []).filter((x) => x.diff)" :key="i" class="border-t border-gray-100 dark:border-gray-700/50">
                            <td class="px-3 py-2">{{ r.type === 'bill' ? 'فرق فاتورة المورّد' : r.label }}<span v-if="r.type === 'shared' && r.currency === 'SAR'" class="text-xs text-gray-400 font-mono" dir="ltr"> ({{ money(r.amount) }} ريال)</span></td>
                            <td class="px-3 py-2 text-xs">{{ r.agent }}</td>
                            <td :class="numCls" dir="ltr">{{ money(r.diff) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <template v-else-if="p">
            <div v-if="!p.is_back" class="p-3 rounded-xl border text-sm bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-200">الرحلة لم تعد بعد. هذه معاينة، والاعتماد بعد تاريخ العودة.</div>
            <div v-if="p.pending" class="p-3 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">في العرض {{ p.pending }} فاتورة معلّقة أو تحت التعديل. أكمل تكاليفها واعتمدها، أو ارفضها، قبل الإقفال.</div>

            <section :class="box">
                <h4 :class="h4">مصاريف الرحلة المشتركة <span class="font-normal text-xs text-gray-400">لا تخصّ مسافراً بعينه: باص مستأجر، مرشد، مشرف، أسرّة لم تُبع وحاسبنا عليها الفندق. بمبلغها الفعلي، مرة واحدة للرحلة</span></h4>
                <div class="space-y-2">
                    <div v-for="(r, i) in inputs.shared" :key="r._k" class="grid grid-cols-2 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1.6fr)_120px_100px_32px] gap-2 items-end p-2 rounded-lg bg-gray-50 dark:bg-gray-800/60">
                        <div class="col-span-2 md:col-span-1">
                            <label :for="'sh-a' + r._k" :class="mini">الوكيل *</label>
                            <select :id="'sh-a' + r._k" v-model.number="r.agent_id" :class="inpSm">
                                <option :value="null">— الوكيل —</option>
                                <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>
                        </div>
                        <div class="col-span-2 md:col-span-1"><label :for="'sh-d' + r._k" :class="mini">البيان *</label><input :id="'sh-d' + r._k" v-model="r.description" maxlength="150" :class="inpSm" placeholder="مثال: باص سياحي مستأجر للرحلة"/></div>
                        <div><label :for="'sh-m' + r._k" :class="mini">المبلغ *</label><input :id="'sh-m' + r._k" v-model.number="r.amount" type="number" step="0.001" min="0" dir="ltr" :class="inpSm + ' font-mono'"/></div>
                        <div>
                            <label :for="'sh-c' + r._k" :class="mini">العملة</label>
                            <select :id="'sh-c' + r._k" v-model="r.currency" :class="inpSm"><option value="JOD">دينار</option><option value="SAR">ريال</option></select>
                        </div>
                        <button type="button" @click="inputs.shared.splice(i, 1)" class="pb-1 text-lg text-gray-400 hover:text-red-500" aria-label="حذف المصروف">&times;</button>
                    </div>
                </div>
                <button type="button" @click="addShared" class="px-3 py-1.5 rounded-lg text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-300">+ مصروف مشترك</button>
            </section>

            <section :class="box">
                <h4 :class="h4">فرق فاتورة كل مورّد <span class="font-normal text-xs text-gray-400">قارن ما قُيِّد عليه من الفواتير بكشفه الفعلي. موجب = رسوم إضافية، سالب = خصم أو استرداد</span></h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr :class="th"><th class="px-3 py-2 text-right">الوكيل</th><th class="px-3 py-2 text-right">مقيّد من الفواتير</th><th class="px-3 py-2 text-right">الفرق</th></tr></thead>
                        <tbody>
                            <tr v-for="r in bill" :key="r.agent_id" class="border-t border-gray-100 dark:border-gray-700/50">
                                <td class="px-3 py-2">{{ r.agent }}</td>
                                <td :class="numCls" dir="ltr">{{ money(r.posted) }}</td>
                                <td class="px-3 py-2">
                                    <label class="sr-only" :for="'bill-' + r.agent_id">فرق {{ r.agent }}</label>
                                    <input :id="'bill-' + r.agent_id" v-model.number="inputs.bill[r.agent_id]" type="number" step="0.001" dir="ltr" placeholder="0.000" class="w-28 px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-mono"/>
                                </td>
                            </tr>
                            <tr v-if="!bill.length"><td colspan="3" class="px-3 py-6 text-center text-gray-400">لا فواتير معتمدة بعد.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section :class="box">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 :class="h4">القيد عند الاعتماد</h4>
                    <span v-if="loading" class="text-xs text-gray-400">...يُعاد الحساب</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr :class="th"><th class="px-3 py-2 text-right">الحساب</th><th class="px-3 py-2 text-right">مدين</th><th class="px-3 py-2 text-right">دائن</th></tr></thead>
                        <tbody>
                            <tr v-if="p.total" class="border-t border-gray-100 dark:border-gray-700/50"><td class="px-3 py-2 font-bold">مصاريف الرحلات المشتركة</td><td :class="numCls" dir="ltr">{{ p.total > 0 ? money(p.total) : '' }}</td><td :class="numCls" dir="ltr">{{ p.total < 0 ? money(-p.total) : '' }}</td></tr>
                            <tr v-for="a in p.per_agent" :key="a.agent_id" class="border-t border-gray-100 dark:border-gray-700/50"><td class="px-3 py-2">{{ a.agent }}</td><td :class="numCls" dir="ltr">{{ a.diff < 0 ? money(-a.diff) : '' }}</td><td :class="numCls" dir="ltr">{{ a.diff > 0 ? money(a.diff) : '' }}</td></tr>
                            <tr v-if="!p.per_agent.length"><td colspan="3" class="px-3 py-6 text-center text-gray-400">لا مبالغ: يُقفل العرض بلا قيد.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-300">ربح العرض يبقى كما هو: هذه مصاريف تُسجَّل في حسابها، ولا تُخصم من ربح العرض.</p>
                <p v-if="p.incomplete" class="text-sm text-amber-700 dark:text-amber-300">{{ p.incomplete }} مصروف مشترك ناقص: أكمل الوكيل والبيان والمبلغ، أو احذفه.</p>
                <p v-if="formError" class="text-sm text-red-600">{{ formError }}</p>
                <button v-if="can.update" type="button" @click="approve" :disabled="p.pending > 0 || !p.is_back || p.incomplete > 0 || approving || loading" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow disabled:opacity-40 disabled:cursor-not-allowed">اعتماد الإقفال</button>
            </section>
        </template>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({ offerId: Number, closing: Object, agents: { type: Array, default: () => [] }, can: Object });

const box = 'rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900';
const h4 = 'text-sm font-bold text-gray-800 dark:text-gray-100';
const mini = 'block text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-0.5';
const inpSm = 'w-full px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none';
const th = 'bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400';
const numCls = 'px-3 py-2 font-mono text-xs tabular-nums';
const money = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const p = ref(props.closing && !props.closing.settled ? props.closing : null);
let uid = 0;
const inputs = reactive({ shared: [], bill: {} });
const loading = ref(false);
const approving = ref(false);
const formError = ref('');

const bill = computed(() => (p.value?.rows || []).filter((r) => r.type === 'bill'));
const addShared = () => inputs.shared.push({ _k: ++uid, agent_id: null, description: '', amount: null, currency: 'JOD' });

const clean = () => ({
    shared: inputs.shared.map((r) => ({ agent_id: r.agent_id || null, description: r.description || '', amount: Number(r.amount) || 0, currency: r.currency })),
    bill: Object.fromEntries(Object.entries(inputs.bill).filter(([, v]) => v !== '' && v !== null && v !== undefined)),
});
const refresh = async () => {
    loading.value = true;
    try {
        const { data } = await axios.post('/offers/' + props.offerId + '/settlement/preview', clean());
        p.value = data;
    } catch (e) {
        // المعاينة السابقة تبقى؛ الاعتماد يتحقق من جديد في الخادم
    } finally {
        loading.value = false;
    }
};
let timer = null;
watch(() => JSON.stringify(clean()), () => {
    clearTimeout(timer);
    timer = setTimeout(refresh, 300);
});
const approve = () => {
    approving.value = true;
    formError.value = '';
    router.post('/offers/' + props.offerId + '/settlement', clean(), {
        preserveScroll: true,
        onError: (e) => { formError.value = Object.values(e).flat().join(' '); },
        onFinish: () => { approving.value = false; },
    });
};
</script>
