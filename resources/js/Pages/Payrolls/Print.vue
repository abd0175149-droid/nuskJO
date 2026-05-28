<template>
    <PrintLayout>
        <div class="print-content" dir="rtl">
            <div class="text-center mb-6">
                <h1 class="text-2xl font-bold mb-1">كشف مسير الرواتب</h1>
                <p class="text-gray-500">{{ monthNames[payroll.month] }} {{ payroll.year }} — {{ payroll.currency }}</p>
                <p class="text-sm text-gray-400">رقم المسير: {{ payroll.payroll_number }}</p>
            </div>

            <table class="w-full border-collapse text-xs mb-6">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border px-2 py-2 text-right">#</th>
                        <th class="border px-2 py-2 text-right">الموظف</th>
                        <th class="border px-2 py-2 text-right">الرقم الوظيفي</th>
                        <th class="border px-2 py-2 text-right">الأساسي</th>
                        <th class="border px-2 py-2 text-right">البدلات</th>
                        <th class="border px-2 py-2 text-right">OT</th>
                        <th class="border px-2 py-2 text-right">الاستحقاقات</th>
                        <th class="border px-2 py-2 text-right">الخصومات</th>
                        <th class="border px-2 py-2 text-right font-bold">الصافي</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(item, idx) in payroll.items" :key="item.id">
                        <td class="border px-2 py-1.5 text-right">{{ idx + 1 }}</td>
                        <td class="border px-2 py-1.5 text-right">{{ item.employee?.user?.name }}</td>
                        <td class="border px-2 py-1.5 text-right font-mono">{{ item.employee?.employee_number }}</td>
                        <td class="border px-2 py-1.5 text-right font-mono" dir="ltr">{{ fmt(item.basic_salary) }}</td>
                        <td class="border px-2 py-1.5 text-right font-mono" dir="ltr">{{ fmt(Number(item.housing_allowance) + Number(item.transport_allowance) + Number(item.other_allowance)) }}</td>
                        <td class="border px-2 py-1.5 text-right font-mono" dir="ltr">{{ fmt(item.overtime_amount) }}</td>
                        <td class="border px-2 py-1.5 text-right font-mono text-green-700" dir="ltr">{{ fmt(item.total_earnings) }}</td>
                        <td class="border px-2 py-1.5 text-right font-mono text-red-700" dir="ltr">{{ fmt(item.total_deductions) }}</td>
                        <td class="border px-2 py-1.5 text-right font-mono font-bold" dir="ltr">{{ fmt(item.net_salary) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="bg-gray-100 font-bold">
                        <td colspan="3" class="border px-2 py-2 text-right">الإجمالي</td>
                        <td class="border px-2 py-2 text-right font-mono" dir="ltr">{{ fmt(payroll.items?.reduce((s,i) => s + Number(i.basic_salary), 0)) }}</td>
                        <td class="border px-2 py-2 text-right font-mono" dir="ltr">{{ fmt(payroll.items?.reduce((s,i) => s + Number(i.housing_allowance) + Number(i.transport_allowance) + Number(i.other_allowance), 0)) }}</td>
                        <td class="border px-2 py-2 text-right font-mono" dir="ltr">{{ fmt(payroll.items?.reduce((s,i) => s + Number(i.overtime_amount), 0)) }}</td>
                        <td class="border px-2 py-2 text-right font-mono text-green-700" dir="ltr">{{ fmt(payroll.total_basic) }}</td>
                        <td class="border px-2 py-2 text-right font-mono text-red-700" dir="ltr">{{ fmt(payroll.total_deductions) }}</td>
                        <td class="border px-2 py-2 text-right font-mono font-bold" dir="ltr">{{ fmt(payroll.total_net) }}</td>
                    </tr>
                </tfoot>
            </table>

            <div class="grid grid-cols-3 gap-8 mt-12 text-center text-xs text-gray-500">
                <div><div class="border-t border-gray-400 pt-2 mt-10">المدير المالي</div></div>
                <div><div class="border-t border-gray-400 pt-2 mt-10">مدير الموارد البشرية</div></div>
                <div><div class="border-t border-gray-400 pt-2 mt-10">المدير العام</div></div>
            </div>
        </div>
    </PrintLayout>
</template>

<script setup>
import PrintLayout from '@/Components/PrintLayout.vue';
const props = defineProps({ payroll: Object });
const monthNames = { 1:'يناير', 2:'فبراير', 3:'مارس', 4:'أبريل', 5:'مايو', 6:'يونيو', 7:'يوليو', 8:'أغسطس', 9:'سبتمبر', 10:'أكتوبر', 11:'نوفمبر', 12:'ديسمبر' };
const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 2 });
</script>
