<template>
    <div class="space-y-5">
        <section v-if="sales.seats.total" :class="box">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 :class="h4">المقاعد</h4>
                <span class="text-xs text-gray-400">تُحسب من الفواتير ولا تُخزَّن: الكلي − (المعلّقة + تحت التعديل + المعتمدة)</span>
            </div>
            <div class="flex h-3 rounded-full overflow-hidden bg-gray-100 dark:bg-gray-700" role="img" :aria-label="`${sales.seats.approved} معتمد، ${sales.seats.pending} معلّق، ${sales.seats.available} متاح من ${sales.seats.total}`">
                <span class="block h-full bg-gold-500" :style="{ width: pct(sales.seats.approved) }"></span>
                <span class="block h-full bg-amber-300 dark:bg-amber-500/70" :style="{ width: pct(sales.seats.pending) }"></span>
            </div>
            <div class="flex flex-wrap gap-4 text-sm">
                <span class="flex items-center gap-1.5"><i class="w-3 h-3 rounded-sm bg-gold-500"></i> معتمد <b class="font-mono tabular-nums">{{ sales.seats.approved }}</b></span>
                <span class="flex items-center gap-1.5"><i class="w-3 h-3 rounded-sm bg-amber-300"></i> معلّق أو تحت التعديل <b class="font-mono tabular-nums">{{ sales.seats.pending }}</b></span>
                <span class="flex items-center gap-1.5"><i class="w-3 h-3 rounded-sm bg-gray-200 dark:bg-gray-600"></i> متاح <b class="font-mono tabular-nums" :class="sales.seats.available <= 0 ? 'text-red-600' : ''">{{ sales.seats.available }}</b></span>
                <span class="text-gray-500">من <span class="font-mono tabular-nums">{{ sales.seats.total }}</span> · البوت يرى «{{ sales.seats.label }}»</span>
            </div>
        </section>

        <section :class="box">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 :class="h4">الفواتير المرتبطة بالعرض <span class="text-gray-400 font-normal">({{ sales.invoices.length }})</span></h4>
                <span class="text-xs text-gray-400">كل فاتورة عرض تحمل رقم العرض ومقاعدها ولقطة ما بيع.</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                        <th class="px-3 py-2 text-right">الرقم</th><th class="px-3 py-2 text-right">العميل</th><th class="px-3 py-2 text-right">الاختيار</th>
                        <th class="px-3 py-2 text-center">مقاعد</th><th class="px-3 py-2 text-right">البيع</th><th v-if="can.cost" class="px-3 py-2 text-right">الربح</th><th class="px-3 py-2 text-right">الحالة</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="i in sales.invoices" :key="i.id" class="border-t border-gray-100 dark:border-gray-700/50" :class="i.status === 'rejected' ? 'opacity-50' : ''">
                            <td class="px-3 py-2"><Link :href="'/invoices?search=' + encodeURIComponent(i.number)" class="font-mono text-xs text-gold-700 hover:underline">{{ i.number }}</Link><div class="text-[11px] text-gray-400">{{ i.seller || '—' }} · <span dir="ltr">{{ i.date }}</span></div></td>
                            <td class="px-3 py-2 text-xs">{{ i.client }}<div class="text-gray-400 font-mono" dir="ltr">{{ i.phone }}</div></td>
                            <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300"><div v-for="(l, k) in i.lines" :key="k">{{ l.text }}<span v-if="l.room"> · {{ l.room }}</span> × {{ l.pax }}</div></td>
                            <td class="px-3 py-2 text-center font-mono tabular-nums">{{ i.seats }}</td>
                            <td class="px-3 py-2 font-mono text-xs tabular-nums" dir="ltr">{{ money(i.sell) }}</td>
                            <td v-if="can.cost" class="px-3 py-2 font-mono text-xs tabular-nums" dir="ltr" :class="i.missing ? 'text-gray-400' : (i.profit >= 0 ? 'text-green-600' : 'text-red-600')">{{ i.missing ? '—' : money(i.profit) }}</td>
                            <td class="px-3 py-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusCls[i.status]">{{ statusLabel[i.status] }}</span>
                                <span v-if="i.missing" class="block mt-1 text-[11px] font-bold text-amber-700 dark:text-amber-300">تكاليف ناقصة ({{ i.missing }})</span>
                            </td>
                        </tr>
                        <tr v-if="!sales.invoices.length"><td :colspan="can.cost ? 7 : 6" class="px-3 py-8 text-center text-gray-400">لا فواتير بعد.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <template v-if="can.cost && sales.pl">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div v-for="k in kpis" :key="k.label" class="rounded-xl border p-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    <span class="block text-xs text-gray-500">{{ k.label }}</span>
                    <b class="font-mono tabular-nums text-lg" :class="k.cls">{{ k.value }}</b> <small class="text-[11px] text-gray-400">{{ k.sub }}</small>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 p-3 rounded-xl border text-sm border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40">
                <span><b>مصاريف الرحلة المشتركة</b> <span class="text-xs text-gray-500">مصروف في «مصاريف الرحلات المشتركة»، لا يُخصم من ربح العرض</span></span>
                <span><b class="font-mono tabular-nums" dir="ltr">{{ money(sales.pl.shared) }}</b> <small class="text-gray-400">{{ sales.pl.settled ? 'معتمدة في الإقفال' : 'تُدخل عند الإقفال' }}</small></span>
            </div>

            <section :class="box">
                <h4 :class="h4">التكلفة الفعلية لكل فندق <span class="font-normal text-xs text-gray-400">من الفواتير المعتمدة: ما دُفع فعلاً، وبأي وكيل</span></h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                            <th class="px-3 py-2 text-right">الفندق والسعة</th><th class="px-3 py-2 text-center">مسافرون</th><th class="px-3 py-2 text-right">متوسط الفرد/ليلة</th><th class="px-3 py-2 text-right">المدى</th><th class="px-3 py-2 text-right">الوكلاء</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="(r, k) in sales.actual" :key="k" class="border-t border-gray-100 dark:border-gray-700/50">
                                <td class="px-3 py-2 text-xs">{{ r.label }}</td>
                                <td class="px-3 py-2 text-center font-mono tabular-nums">{{ r.pax }}</td>
                                <td class="px-3 py-2 font-mono text-xs tabular-nums" dir="ltr">{{ num(r.avg) }} {{ r.currency === 'SAR' ? 'ريال' : 'د.أ' }}</td>
                                <td class="px-3 py-2 font-mono text-xs tabular-nums" dir="ltr">{{ num(r.min) }}–{{ num(r.max) }}</td>
                                <td class="px-3 py-2 text-xs">{{ r.agents.join('، ') }}</td>
                            </tr>
                            <tr v-if="!sales.actual?.length"><td colspan="5" class="px-3 py-6 text-center text-gray-400">لا فواتير معتمدة بعد.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section :class="box">
                <h4 :class="h4">ما ندين به لكل وكيل عن هذا العرض</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                            <th class="px-3 py-2 text-right">الوكيل</th><th class="px-3 py-2 text-right">مقيّد عبر الفواتير</th><th class="px-3 py-2 text-right">من الإقفال</th><th class="px-3 py-2 text-right">مدفوع له</th><th class="px-3 py-2 text-right">المتبقي له</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="a in sales.agents" :key="a.agent_id" class="border-t border-gray-100 dark:border-gray-700/50">
                                <td class="px-3 py-2">{{ a.agent }}</td>
                                <td class="px-3 py-2 font-mono text-xs tabular-nums" dir="ltr">{{ money(a.posted) }}</td>
                                <td class="px-3 py-2 font-mono text-xs tabular-nums" dir="ltr">{{ money(a.settlement) }}</td>
                                <td class="px-3 py-2 font-mono text-xs tabular-nums" dir="ltr">{{ money(a.paid) }}</td>
                                <td class="px-3 py-2 font-mono text-xs tabular-nums font-bold" dir="ltr" :class="a.remaining < 0 ? 'text-red-600' : ''">{{ money(a.remaining) }}</td>
                            </tr>
                            <tr v-if="!sales.agents.length"><td colspan="5" class="px-3 py-6 text-center text-gray-400">لا فواتير معتمدة بعد.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p :class="hint">«مدفوع له» من سندات الصرف المعتمدة على حساب الوكيل والمربوطة بهذا العرض (حقل «عن العرض» في سند الصرف).</p>
            </section>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ sales: Object, can: Object });

const box = 'rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900';
const h4 = 'text-sm font-bold text-gray-800 dark:text-gray-100';
const hint = 'text-xs text-gray-400 dark:text-gray-500';
const statusLabel = { pending: 'معلّقة', approved: 'معتمدة', rejected: 'مرفوضة', editing: 'تحت التعديل' };
const statusCls = {
    pending: 'bg-yellow-100 text-yellow-700', approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700', editing: 'bg-blue-100 text-blue-700',
};
const money = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const pct = (n) => (props.sales.seats.total ? Math.min(100, (n / props.sales.seats.total) * 100) : 0) + '%';
const num = (v) => Number(v || 0).toLocaleString('en', { maximumFractionDigits: 2 });
// ربح العرض = مجموع أرباح فواتيره المعتمدة؛ مصاريف الإقفال المشتركة مصروف مستقل لا يُخصم منه
const kpis = computed(() => {
    const p = props.sales.pl;
    return [
        { label: 'مسافرون معتمدون', value: p.pax, sub: props.sales.seats.total ? 'من ' + props.sales.seats.total : '' },
        { label: 'المبيعات المعتمدة', value: money(p.sales), sub: 'د.أ' },
        { label: 'تكاليف الفواتير', value: money(p.cost), sub: 'د.أ' },
        { label: 'ربح العرض', value: money(p.profit), sub: p.sales ? ((p.profit / p.sales) * 100).toFixed(1) + '%' : '', cls: p.profit >= 0 ? 'text-green-600' : 'text-red-600' },
    ];
});
</script>
