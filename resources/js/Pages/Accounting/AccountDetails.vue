<template>
    <AppLayout>
        <template #header>{{ title }}</template>
        <div class="space-y-6">
            <!-- معلومات الحساب -->
            <div class="bg-white dark:bg-gray-900 rounded-2xl border p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ account.code }} — {{ account.name }}</h2>
                        <div class="flex items-center gap-4 mt-1 text-xs text-gray-500">
                            <span>النوع: <strong>{{ typeLabels[account.type] }}</strong></span>
                            <span>العملة: <strong>{{ account.currency }}</strong></span>
                            <span>الرصيد الحالي: <strong class="text-lg" :class="Number(account.balance) >= 0 ? 'text-green-600' : 'text-red-600'">{{ Math.abs(Number(account.balance)).toLocaleString('en',{minimumFractionDigits:3}) }}</strong></span>
                        </div>
                    </div>
                    <a href="/accounting/chart-of-accounts" class="px-4 py-2 rounded-xl text-sm text-gray-600 hover:bg-gray-100 border">← العودة للشجرة</a>
                </div>
            </div>

            <!-- فلاتر التاريخ -->
            <div class="flex flex-wrap items-end gap-3">
                <div><label class="block text-xs font-medium text-gray-600 mb-1">من</label><input v-model="fromDate" type="date" class="px-4 py-2 rounded-xl border text-sm" dir="ltr"/></div>
                <div><label class="block text-xs font-medium text-gray-600 mb-1">إلى</label><input v-model="toDate" type="date" class="px-4 py-2 rounded-xl border text-sm" dir="ltr"/></div>
                <button @click="applyFilter" class="px-5 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400">🔍 عرض</button>
                <a :href="`/accounting/accounts/${account.id}/print?from=${fromDate}&to=${toDate}`" target="_blank" class="px-4 py-2 rounded-xl text-sm text-purple-600 border border-purple-200 hover:bg-purple-50">🖨️ طباعة</a>
            </div>

            <!-- جدول الحركات -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                        <th class="px-4 py-3 text-right font-bold">التاريخ</th>
                        <th class="px-4 py-3 text-right font-bold">رقم القيد</th>
                        <th class="px-4 py-3 text-right font-bold">البيان</th>
                        <th class="px-4 py-3 text-right font-bold">المرجع</th>
                        <th class="px-4 py-3 text-right font-bold">مدين</th>
                        <th class="px-4 py-3 text-right font-bold">دائن</th>
                        <th class="px-4 py-3 text-right font-bold">الرصيد</th>
                    </tr></thead>
                    <tbody>
                        <!-- الرصيد الافتتاحي -->
                        <tr class="bg-blue-50 dark:bg-blue-900/20 font-bold">
                            <td class="px-4 py-3 text-right text-xs" colspan="4">الرصيد الافتتاحي</td>
                            <td class="px-4 py-3 text-right font-mono text-xs" colspan="2"></td>
                            <td class="px-4 py-3 text-right font-mono text-xs font-bold" :class="opening_balance >= 0 ? 'text-green-600' : 'text-red-600'">{{ fmtAbs(opening_balance) }}</td>
                        </tr>
                        <!-- الحركات -->
                        <tr v-for="(line, i) in lines" :key="i" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30" :class="{'opacity-50 line-through': line.is_reversed}">
                            <td class="px-4 py-3 text-right text-xs font-mono text-gray-500" dir="ltr">{{ line.entry_date?.split('T')[0] || line.entry_date }}</td>
                            <td class="px-4 py-3 text-right text-xs font-mono text-gold-700">{{ line.entry_number }}</td>
                            <td class="px-4 py-3 text-right text-xs max-w-[250px]">{{ line.description || line.entry_description }}</td>
                            <td class="px-4 py-3 text-right text-xs"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold" :class="refClass(line.reference_type)">{{ refLabels[line.reference_type] || line.reference_type }}</span></td>
                            <td class="px-4 py-3 text-right font-mono text-xs" :class="Number(line.debit) > 0 ? 'text-red-600 font-bold' : 'text-gray-300'">{{ fmt(line.debit) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs" :class="Number(line.credit) > 0 ? 'text-green-600 font-bold' : 'text-gray-300'">{{ fmt(line.credit) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs font-bold" :class="runningBalance(i) >= 0 ? 'text-green-700' : 'text-red-700'">{{ fmtAbs(runningBalance(i)) }}</td>
                        </tr>
                        <tr v-if="!lines.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا يوجد حركات في هذه الفترة</td></tr>
                    </tbody>
                    <!-- المجاميع -->
                    <tfoot v-if="lines.length">
                        <tr class="bg-gray-100 dark:bg-gray-800 font-bold border-t-2 border-gray-300">
                            <td class="px-4 py-3 text-right" colspan="4">المجموع</td>
                            <td class="px-4 py-3 text-right font-mono text-red-700">{{ fmt(totals.debit) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-green-700">{{ fmt(totals.credit) }}</td>
                            <td class="px-4 py-3 text-right font-mono font-bold" :class="closingBalance >= 0 ? 'text-green-700' : 'text-red-700'">{{ fmtAbs(closingBalance) }}</td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';

const props = defineProps({
    title: String, account: Object, opening_balance: Number,
    lines: Array, totals: Object, filters: Object,
});

const fromDate = ref(props.filters?.from || '');
const toDate = ref(props.filters?.to || '');

const typeLabels = { asset:'أصول', liability:'التزامات', equity:'حقوق ملكية', revenue:'إيرادات', expense:'مصروفات', disbursement:'سند صرف' };
const refLabels = { receipt:'سند قبض', disbursement:'سند صرف', transfer:'حوالة', invoice:'فاتورة', expense:'مصروف', violation:'مخالفة', advance:'سلفة', payroll:'رواتب', reversal:'عكس', manual:'يدوي', year_closing:'إقفال' };

const refClass = (type) => ({
    receipt:'bg-green-100 text-green-700', disbursement:'bg-green-100 text-green-700', transfer:'bg-blue-100 text-blue-700',
    invoice:'bg-purple-100 text-purple-700', expense:'bg-red-100 text-red-700',
    violation:'bg-orange-100 text-orange-700', reversal:'bg-gray-200 text-gray-600',
    manual:'bg-yellow-100 text-yellow-700', year_closing:'bg-indigo-100 text-indigo-700'
}[type] || 'bg-gray-100 text-gray-500');

const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3 });
const fmtAbs = (v) => Math.abs(Number(v || 0)).toLocaleString('en', { minimumFractionDigits: 3 });

const isDebitNature = computed(() => ['asset', 'expense'].includes(props.account.type));

const runningBalance = (index) => {
    let balance = props.opening_balance || 0;
    for (let i = 0; i <= index; i++) {
        const d = Number(props.lines[i].debit || 0);
        const c = Number(props.lines[i].credit || 0);
        balance += isDebitNature.value ? (d - c) : (c - d);
    }
    return balance;
};

const closingBalance = computed(() => {
    if (!props.lines.length) return props.opening_balance;
    return runningBalance(props.lines.length - 1);
});

const applyFilter = () => {
    router.get('/accounting/accounts/' + props.account.id + '/details', {
        from: fromDate.value, to: toDate.value,
    }, { preserveState: true, replace: true });
};
</script>
