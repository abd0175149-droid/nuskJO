<template>
    <AppLayout>
        <template #header>تقرير الرواتب</template>
        <div class="space-y-6">
            <div class="flex gap-3 flex-wrap">
                <select v-model="f.month" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter"><option v-for="m in 12" :key="m" :value="m">{{ monthNames[m] }}</option></select>
                <select v-model="f.year" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter"><option v-for="y in [2025,2026,2027]" :key="y" :value="y">{{ y }}</option></select>
            </div>

            <div v-for="payroll in payrolls" :key="payroll.id" class="space-y-3">
                <div class="flex items-center justify-between bg-gradient-to-r from-gold-500 to-gold-400 rounded-xl p-4 shadow">
                    <div>
                        <span class="font-bold text-black">{{ payroll.payroll_number }}</span>
                        <span class="text-black/60 text-sm mr-3">{{ monthNames[payroll.month] }} {{ payroll.year }} — {{ payroll.currency }}</span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(payroll.status)">{{ statusLabel(payroll.status) }}</span>
                    </div>
                    <div class="text-left">
                        <div class="text-sm text-black/60">الصافي</div>
                        <div class="text-xl font-bold text-black" dir="ltr">{{ fmt(payroll.total_net) }} {{ payroll.currency }}</div>
                    </div>
                </div>

                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <th class="px-3 py-2 text-right font-bold text-xs">الموظف</th>
                        <th class="px-3 py-2 text-right font-bold text-xs">القسم</th>
                        <th class="px-3 py-2 text-right font-bold text-xs">الأساسي</th>
                        <th class="px-3 py-2 text-right font-bold text-xs">البدلات</th>
                        <th class="px-3 py-2 text-right font-bold text-xs">OT</th>
                        <th class="px-3 py-2 text-right font-bold text-xs">الاستحقاقات</th>
                        <th class="px-3 py-2 text-right font-bold text-xs">الخصومات</th>
                        <th class="px-3 py-2 text-right font-bold text-xs">الصافي</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="i in payroll.items" :key="i.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50">
                            <td class="px-3 py-2 text-right text-xs">{{ i.employee?.user?.name }}</td>
                            <td class="px-3 py-2 text-right text-xs text-gray-500">{{ i.employee?.department?.name || '—' }}</td>
                            <td class="px-3 py-2 text-right font-mono text-xs" dir="ltr">{{ fmt(i.basic_salary) }}</td>
                            <td class="px-3 py-2 text-right font-mono text-xs" dir="ltr">{{ fmt(Number(i.housing_allowance)+Number(i.transport_allowance)+Number(i.other_allowance)) }}</td>
                            <td class="px-3 py-2 text-right font-mono text-xs text-blue-600" dir="ltr">{{ fmt(i.overtime_amount) }}</td>
                            <td class="px-3 py-2 text-right font-mono text-xs text-green-600 font-bold" dir="ltr">{{ fmt(i.total_earnings) }}</td>
                            <td class="px-3 py-2 text-right font-mono text-xs text-red-600 font-bold" dir="ltr">{{ fmt(i.total_deductions) }}</td>
                            <td class="px-3 py-2 text-right font-mono text-xs font-bold" dir="ltr">{{ fmt(i.net_salary) }}</td>
                        </tr>
                    </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <div v-if="!payrolls.length" class="text-center py-12 text-gray-400">لا يوجد مسيرات لهذه الفترة</div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
const props = defineProps({ payrolls: Array, filters: Object });
const monthNames = {1:'يناير',2:'فبراير',3:'مارس',4:'أبريل',5:'مايو',6:'يونيو',7:'يوليو',8:'أغسطس',9:'سبتمبر',10:'أكتوبر',11:'نوفمبر',12:'ديسمبر'};
const fmt = (v) => Number(v||0).toLocaleString('en',{minimumFractionDigits:2});
const f = ref({ month: props.filters?.month, year: props.filters?.year });
const applyFilter = () => router.get('/hr/reports/payroll', {...f.value}, { preserveState: true, replace: true });
const statusClass = (s) => ({draft:'bg-gray-200 text-gray-700',pending:'bg-yellow-200 text-yellow-800',approved:'bg-green-200 text-green-800',rejected:'bg-red-200 text-red-800'}[s]||'');
const statusLabel = (s) => ({draft:'مسودة',pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة'}[s]||s);
</script>
