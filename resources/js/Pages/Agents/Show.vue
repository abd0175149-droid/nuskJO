<template>
    <AppLayout>
        <template #header>كشف حساب الوكيل: {{ agent.name }}</template>
        <div class="space-y-6">
            <!-- Agent Info Card -->
            <div class="dash-card p-5">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mobile-form-grid">
                    <div><span class="text-gray-400 text-xs">الكود</span><p class="font-mono text-gold-500">{{ agent.code }}</p></div>
                    <div><span class="text-gray-400 text-xs">الدولة</span><p>{{ {JO:'🇯🇴 الأردن',SA:'🇸🇦 السعودية'}[agent.country] }}</p></div>
                    <div><span class="text-gray-400 text-xs">الهاتف</span><p dir="ltr">{{ agent.phone||'—' }}</p></div>
                    <div><span class="text-gray-400 text-xs">الرصيد الحالي</span><p class="font-bold font-mono text-lg" dir="ltr" :class="parseFloat(summary?.current_balance)>=0?'text-green-500':'text-red-500'">{{ Math.abs(Number(summary?.current_balance||0)).toLocaleString('en',{minimumFractionDigits:3}) }} JOD</p></div>
                </div>
            </div>

            <!-- Date Filter -->
            <form @submit.prevent="applyFilter" class="flex flex-wrap items-end gap-4 filter-bar">
                <div><label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">من</label><input v-model="from" type="date" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                <div><label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">إلى</label><input v-model="to" type="date" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                <button type="submit" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 w-full sm:w-auto">🔍 عرض</button>
                <a :href="'/agents/'+agent.id+'/print-statement?from='+from+'&to='+to" target="_blank" class="px-5 py-2.5 rounded-xl font-bold text-sm text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 text-center w-full sm:w-auto">🖨️ طباعة</a>
                <a href="/agents" class="px-5 py-2.5 rounded-xl text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800 text-center w-full sm:w-auto">← الرجوع</a>
            </form>

            <!-- Summary -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="dash-card p-4 text-center"><p class="text-xs text-gray-400">رصيد افتتاحي</p><p class="font-bold font-mono mt-1" dir="ltr">{{ fmt(summary.opening_balance) }}</p></div>
                <div class="dash-card p-4 text-center"><p class="text-xs text-gray-400">إجمالي المدين</p><p class="font-bold font-mono text-red-500 mt-1" dir="ltr">{{ fmt(summary.total_debit) }}</p></div>
                <div class="dash-card p-4 text-center"><p class="text-xs text-gray-400">إجمالي الدائن</p><p class="font-bold font-mono text-green-500 mt-1" dir="ltr">{{ fmt(summary.total_credit) }}</p></div>
                <div class="dash-card p-4 text-center"><p class="text-xs text-gray-400">الرصيد الختامي</p><p class="font-bold font-mono text-lg mt-1" dir="ltr" :class="parseFloat(summary?.current_balance)>=0?'text-green-500':'text-red-500'">{{ fmtAbs(summary?.current_balance) }} JOD</p></div>
            </div>

            <!-- Ledger Table -->
            <div class="dash-card overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full text-sm responsive-table">
                    <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 text-right font-bold">التاريخ</th>
                        <th class="px-4 py-3 text-right font-bold">الوصف</th>
                        <th class="px-4 py-3 text-right font-bold hide-mobile">النوع</th>
                        <th class="px-4 py-3 text-right font-bold">مدين</th>
                        <th class="px-4 py-3 text-right font-bold">دائن</th>
                        <th class="px-4 py-3 text-right font-bold">الرصيد</th>
                        <th class="px-4 py-3 text-center font-bold hide-mobile">إجراءات</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="e in entries" :key="e.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td data-label="التاريخ" class="px-4 py-3 text-right font-mono text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ e.entry_date?.split('T')[0] }}</td>
                            <td data-label="الوصف" class="px-4 py-3 text-right text-xs text-gray-700 dark:text-gray-300">{{ e.description }}</td>
                            <td data-label="النوع" class="px-4 py-3 text-right hide-mobile"><span class="px-2 py-0.5 rounded text-xs font-bold" :class="typeClass(e.transaction_type)">{{ typeLabel(e.transaction_type) }}</span></td>
                            <td data-label="مدين" class="px-4 py-3 text-right font-mono text-xs" dir="ltr" :class="parseFloat(e.debit)>0?'text-red-500 font-bold':'text-gray-300 dark:text-gray-600'">{{ parseFloat(e.debit)>0?Number(e.debit).toFixed(2):'—' }}</td>
                            <td data-label="دائن" class="px-4 py-3 text-right font-mono text-xs" dir="ltr" :class="parseFloat(e.credit)>0?'text-green-500 font-bold':'text-gray-300 dark:text-gray-600'">{{ parseFloat(e.credit)>0?Number(e.credit).toFixed(2):'—' }}</td>
                            <td data-label="الرصيد" class="px-4 py-3 text-right font-mono text-xs font-bold" dir="ltr" :class="parseFloat(e.balance_after)>=0?'text-green-500':'text-red-500'">{{ Math.abs(Number(e.balance_after)).toFixed(2) }}</td>
                            <td data-label="" class="px-4 py-3 text-center whitespace-nowrap hide-mobile actions-cell">
                                <a v-if="printUrl(e)" :href="printUrl(e)" target="_blank" class="px-2 py-1 text-xs text-purple-600 hover:bg-purple-50 rounded-lg btn-mobile-sm">🖨️</a>
                                <span v-else class="text-xs text-gray-300">—</span>
                            </td>
                        </tr>
                        <tr v-if="!entries?.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا يوجد حركات في هذه الفترة</td></tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
const props = defineProps({ agent: Object, entries: Array, summary: Object, filters: Object });
const from = ref(props.filters?.from||'');
const to = ref(props.filters?.to||'');
const fmt = (v) => Number(v||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});
const fmtAbs = (v) => Math.abs(Number(v||0)).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});
const applyFilter = () => { router.get('/agents/'+props.agent.id,{from:from.value,to:to.value},{preserveState:true,replace:true}); };
const typeLabel = (t) => ({disbursement:'سند صرف',transfer:'حوالة',violation:'مخالفة',invoice:'فاتورة',receipt:'سند قبض',expense:'مصروف'}[t]||t);
const typeClass = (t) => ({disbursement:'bg-green-500/20 text-green-400',transfer:'bg-green-500/20 text-green-400',violation:'bg-red-500/20 text-red-400',invoice:'bg-blue-500/20 text-blue-400',receipt:'bg-purple-500/20 text-purple-400',expense:'bg-amber-500/20 text-amber-400',reversal:'bg-gray-500/20 text-gray-400'}[t]||'bg-gray-500/20 text-gray-400');
const printUrl = (e) => {
    const urls = {
        disbursement: `/disbursements/${e.transaction_id}/print`,
        transfer: `/transfers/${e.transaction_id}/print`,
        invoice: `/invoices/${e.transaction_id}/print`,
        receipt: `/receipts/${e.transaction_id}/print`,
        expense: `/expenses/${e.transaction_id}/print`,
    };
    return e.transaction_id ? (urls[e.transaction_type] || null) : null;
};
</script>
<style scoped>
.dash-card { background:white; border:1px solid #e5e7eb; border-radius:1rem; box-shadow:0 1px 2px 0 rgb(0 0 0/0.05); }
:root.dark .dash-card { background:rgba(30,41,59,0.7); border-color:rgba(71,85,105,0.4); }
</style>
