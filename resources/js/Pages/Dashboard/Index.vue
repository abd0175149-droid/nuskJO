<template>
    <AppLayout>
        <template #header>لوحة القيادة</template>
        <div class="space-y-6">

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="dash-card p-5">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">إجمالي أرصدة الوكلاء</p>
                    <p class="text-xl font-bold font-mono text-green-500" dir="ltr">{{ fmt(Math.abs(stats.agents_balance_jod), 3) }} <span class="text-xs text-gray-400">JOD</span></p>
                    <p class="text-xs text-gray-400 mt-1">{{ stats.total_agents }} وكيل نشط</p>
                </div>
                <div class="dash-card p-5">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">إجمالي ذمم العملاء</p>
                    <p class="text-xl font-bold font-mono text-blue-400" dir="ltr">{{ fmt(stats.clients_balance_jod, 3) }} <span class="text-xs text-gray-400">JOD</span></p>
                    <p class="text-xs text-gray-400 mt-1">{{ stats.total_clients }} عميل نشط</p>
                </div>
                <div class="dash-card p-5">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">سعر الصرف</p>
                    <p class="text-xl font-bold font-mono text-gold-500" dir="ltr">{{ Number(exchangeRate).toFixed(6) }}</p>
                    <p class="text-xs text-gray-400 mt-1">SAR → JOD</p>
                </div>
                <div class="dash-card p-5 cursor-pointer hover:border-gold-500/50" @click="showPending=!showPending">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">عمليات معلقة</p>
                    <p class="text-xl font-bold" :class="pending.total>0?'text-amber-400':'text-green-500'">{{ pending.total }}</p>
                    <p class="text-xs text-gray-400 mt-1">اضغط للتفاصيل</p>
                </div>
            </div>

            <!-- Pending Breakdown -->
            <div v-if="showPending && pending.total > 0" class="rounded-2xl p-4 border border-amber-500/30 bg-amber-500/10">
                <h4 class="font-bold text-sm text-amber-400 mb-3">⏳ عمليات بانتظار الاعتماد</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <a v-if="pending.disbursements" href="/disbursements" class="flex items-center gap-2 p-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-amber-400 dark:hover:border-amber-500 transition-colors"><span>💱</span><span class="text-gray-700 dark:text-gray-200">{{ pending.disbursements }} سند صرف</span></a>
                    <a v-if="pending.receipts" href="/receipts" class="flex items-center gap-2 p-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-amber-400 dark:hover:border-amber-500 transition-colors"><span>📄</span><span class="text-gray-700 dark:text-gray-200">{{ pending.receipts }} سند قبض</span></a>
                    <a v-if="pending.invoices" href="/invoices" class="flex items-center gap-2 p-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-amber-400 dark:hover:border-amber-500 transition-colors"><span>🧾</span><span class="text-gray-700 dark:text-gray-200">{{ pending.invoices }} فاتورة</span></a>
                </div>
            </div>

            <!-- Monthly Summary -->
            <div class="dash-card p-5">
                <h4 class="font-bold text-sm text-gray-700 dark:text-gray-200 mb-4">📊 ملخص الشهر الحالي</h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 text-sm">
                    <div class="text-center p-3 rounded-xl bg-green-500/10 border border-green-500/20"><p class="text-xs text-gray-500 dark:text-gray-400">سندات الصرف</p><p class="font-bold font-mono text-green-500 mt-1" dir="ltr">{{ fmt(monthly.disbursements_jod, 3) }}</p><p class="text-xs text-gray-400">JOD</p></div>
                    <div class="text-center p-3 rounded-xl bg-blue-500/10 border border-blue-500/20"><p class="text-xs text-gray-500 dark:text-gray-400">سندات القبض</p><p class="font-bold font-mono text-blue-400 mt-1" dir="ltr">{{ fmt(monthly.receipts_jod, 3) }}</p><p class="text-xs text-gray-400">JOD</p></div>
                    <div class="text-center p-3 rounded-xl bg-purple-500/10 border border-purple-500/20"><p class="text-xs text-gray-500 dark:text-gray-400">الفواتير</p><p class="font-bold font-mono text-purple-400 mt-1" dir="ltr">{{ fmt(monthly.invoices_jod, 3) }}</p><p class="text-xs text-gray-400">JOD</p></div>
                </div>
            </div>

            <!-- Chart -->
            <div class="dash-card p-5">
                <h4 class="font-bold text-sm text-gray-700 dark:text-gray-200 mb-4">📈 حركة آخر 6 أشهر</h4>
                <div class="h-48 flex items-end gap-2">
                    <div v-for="d in chartData" :key="d.month" class="flex-1 flex flex-col items-center gap-1">
                        <div class="w-full flex flex-col items-center gap-0.5">
                            <div class="w-full bg-blue-500 rounded-t opacity-80" :style="{height: barH(d.invoices, maxChart) + 'px'}" :title="'فواتير: ' + d.invoices.toFixed(2)"></div>
                            <div class="w-full bg-green-500 rounded-t opacity-80" :style="{height: barH(d.disbursements, maxChart) + 'px'}" :title="'سندات صرف: ' + d.disbursements.toFixed(2)"></div>
                        </div>
                        <span class="text-xs text-gray-400 font-mono">{{ d.month.split('-')[1] }}/{{ d.month.split('-')[0].slice(2) }}</span>
                    </div>
                </div>
                <div class="flex items-center justify-center gap-6 mt-3 text-xs text-gray-400">
                    <span class="flex items-center gap-1"><span class="w-3 h-3 bg-blue-500 rounded opacity-80"></span> فواتير</span>
                    <span class="flex items-center gap-1"><span class="w-3 h-3 bg-green-500 rounded opacity-80"></span> سندات صرف</span>
                </div>
            </div>



            <!-- Recent Tables -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="dash-card p-5">
                    <h4 class="font-bold text-sm text-gray-700 dark:text-gray-200 mb-3">💱 آخر سندات الصرف</h4>
                    <div class="space-y-2">
                        <a v-for="t in recentTransfers" :key="t.id" href="/disbursements" class="flex items-center justify-between p-2 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700/50 text-sm transition-colors">
                            <div>
                                <span class="font-mono text-xs text-gold-500">{{ t.disbursement_number }}</span>
                                <span class="text-gray-500 dark:text-gray-400 text-xs mr-2">{{ t.beneficiary_name || t.agent?.name || '—' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="font-bold font-mono text-xs text-gray-700 dark:text-gray-200" dir="ltr">{{ fmt(t.amount, 2) }} {{ t.currency }}</span>
                                <span class="px-1.5 py-0.5 rounded text-xs font-bold" :class="statusClass(t.status)">{{ {pending:'⏳',approved:'✅',rejected:'❌'}[t.status] }}</span>
                            </div>
                        </a>
                        <p v-if="!recentTransfers?.length" class="text-center text-gray-400 text-xs py-4">لا يوجد</p>
                    </div>
                </div>
                <div class="dash-card p-5">
                    <h4 class="font-bold text-sm text-gray-700 dark:text-gray-200 mb-3">🧾 آخر الفواتير</h4>
                    <div class="space-y-2">
                        <a v-for="inv in recentInvoices" :key="inv.id" href="/invoices" class="flex items-center justify-between p-2 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700/50 text-sm transition-colors">
                            <div>
                                <span class="font-mono text-xs text-gold-500">{{ inv.invoice_number }}</span>
                                <span class="text-gray-500 dark:text-gray-400 text-xs mr-2">{{ inv.client?.name }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="font-bold font-mono text-xs text-blue-400" dir="ltr">{{ fmt(inv.total_jod, 3) }} JOD</span>
                                <span class="px-1.5 py-0.5 rounded text-xs font-bold" :class="statusClass(inv.status)">{{ {pending:'⏳',approved:'✅',rejected:'❌'}[inv.status] }}</span>
                            </div>
                        </a>
                        <p v-if="!recentInvoices?.length" class="text-center text-gray-400 text-xs py-4">لا يوجد</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ stats: Object, pending: Object, recentTransfers: Array, recentInvoices: Array, monthly: Object, chartData: Array, exchangeRate: Number });
const showPending = ref(props.pending?.total > 0);

const fmt = (v, d) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: d, maximumFractionDigits: d });
const maxChart = computed(() => {
    if (!props.chartData?.length) return 1;
    return Math.max(1, ...props.chartData.map(d => Math.max(d.invoices, d.disbursements)));
});
const barH = (val, max) => Math.max(2, (val / max) * 120);
const statusClass = (s) => ({
    pending: 'bg-amber-500/20 text-amber-400',
    approved: 'bg-green-500/20 text-green-400',
    rejected: 'bg-red-500/20 text-red-400',
}[s] || '');
</script>

<style scoped>
.dash-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 1rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
:root.dark .dash-card {
    background: rgba(30, 41, 59, 0.7);
    border-color: rgba(71, 85, 105, 0.4);
}
</style>
