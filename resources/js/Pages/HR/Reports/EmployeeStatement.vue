<template>
    <AppLayout>
        <template #header>كشف حساب موظف — {{ employee.user?.name }}</template>
        <div class="space-y-6">
            <!-- Employee Info -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="text-xs text-gray-500">الرقم الوظيفي</div><div class="text-sm font-bold font-mono">{{ employee.employee_number }}</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="text-xs text-gray-500">القسم</div><div class="text-sm font-bold">{{ employee.department?.name || '—' }}</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="text-xs text-gray-500">الراتب الإجمالي</div><div class="text-sm font-bold text-green-600" dir="ltr">{{ fmt(totalSalary) }} {{ employee.currency }}</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="text-xs text-gray-500">السنة</div>
                    <select v-model="f.year" class="mt-1 px-3 py-1.5 rounded-lg border border-gray-200 text-sm w-full" @change="applyFilter"><option v-for="y in [2025,2026,2027]" :key="y" :value="y">{{ y }}</option></select>
                </div>
            </div>

            <!-- Payroll History -->
            <div>
                <h3 class="text-md font-bold text-gray-800 dark:text-gray-200 mb-3">💰 سجل الرواتب</h3>
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <th class="px-4 py-2 text-right font-bold text-xs">الشهر</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">الأساسي</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">البدلات</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">OT</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">الاستحقاقات</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">الخصومات</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">الصافي</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="i in payrollItems" :key="i.id" class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-2 text-right text-xs">{{ monthNames[i.payroll?.month] }}</td>
                            <td class="px-4 py-2 text-right font-mono text-xs" dir="ltr">{{ fmt(i.basic_salary) }}</td>
                            <td class="px-4 py-2 text-right font-mono text-xs" dir="ltr">{{ fmt(Number(i.housing_allowance)+Number(i.transport_allowance)+Number(i.other_allowance)) }}</td>
                            <td class="px-4 py-2 text-right font-mono text-xs text-blue-600" dir="ltr">{{ fmt(i.overtime_amount) }}</td>
                            <td class="px-4 py-2 text-right font-mono text-xs text-green-600" dir="ltr">{{ fmt(i.total_earnings) }}</td>
                            <td class="px-4 py-2 text-right font-mono text-xs text-red-600" dir="ltr">{{ fmt(i.total_deductions) }}</td>
                            <td class="px-4 py-2 text-right font-mono text-xs font-bold" dir="ltr">{{ fmt(i.net_salary) }}</td>
                        </tr>
                        <tr v-if="!payrollItems.length"><td colspan="7" class="px-5 py-6 text-center text-gray-400">لا يوجد رواتب</td></tr>
                    </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <!-- Advances -->
            <div>
                <h3 class="text-md font-bold text-gray-800 dark:text-gray-200 mb-3">💳 السلف</h3>
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <th class="px-4 py-2 text-right font-bold text-xs">الرقم</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">المبلغ</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">الأقساط</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">المتبقي</th>
                        <th class="px-4 py-2 text-right font-bold text-xs">الحالة</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="a in advances" :key="a.id" class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-2 text-right font-mono text-xs text-gold-700">{{ a.advance_number }}</td>
                            <td class="px-4 py-2 text-right font-mono text-xs" dir="ltr">{{ fmt(a.amount) }}</td>
                            <td class="px-4 py-2 text-right text-xs">{{ a.installments_count }}</td>
                            <td class="px-4 py-2 text-right font-mono text-xs" dir="ltr" :class="Number(a.remaining_amount)>0?'text-red-600':'text-green-600'">{{ fmt(a.remaining_amount) }}</td>
                            <td class="px-4 py-2 text-right"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="sClass(a.status)">{{ sLabel(a.status) }}</span></td>
                        </tr>
                        <tr v-if="!advances.length"><td colspan="5" class="px-5 py-6 text-center text-gray-400">لا يوجد سلف</td></tr>
                    </tbody>
                    </table>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
const props = defineProps({ employee: Object, payrollItems: Array, advances: Array, leaves: Array, filters: Object });
const monthNames = {1:'يناير',2:'فبراير',3:'مارس',4:'أبريل',5:'مايو',6:'يونيو',7:'يوليو',8:'أغسطس',9:'سبتمبر',10:'أكتوبر',11:'نوفمبر',12:'ديسمبر'};
const fmt = (v) => Number(v||0).toLocaleString('en',{minimumFractionDigits:2});
const f = ref({ year: props.filters?.year });
const applyFilter = () => router.get('/hr/reports/employee-statement/'+props.employee.id, {...f.value}, { preserveState: true, replace: true });
const totalSalary = computed(() => Number(props.employee.basic_salary||0)+Number(props.employee.housing_allowance||0)+Number(props.employee.transport_allowance||0)+Number(props.employee.other_allowance||0));
const sClass = (s) => ({pending:'bg-yellow-100 text-yellow-700',approved:'bg-green-100 text-green-700',rejected:'bg-red-100 text-red-700'}[s]||'');
const sLabel = (s) => ({pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة'}[s]||s);
</script>
