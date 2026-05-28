<template>
    <PrintLayout>
        <div class="print-content" dir="rtl">
            <div class="text-center mb-6">
                <h1 class="text-xl font-bold mb-1">قسيمة راتب</h1>
                <p class="text-gray-500 text-sm">{{ monthNames[month] }} {{ year }}</p>
            </div>

            <!-- Employee Info -->
            <div class="grid grid-cols-2 gap-4 mb-6 text-sm">
                <div class="space-y-1">
                    <div><strong>الاسم:</strong> {{ item.employee?.user?.name }}</div>
                    <div><strong>الرقم الوظيفي:</strong> {{ item.employee?.employee_number }}</div>
                    <div><strong>القسم:</strong> {{ item.employee?.department?.name || '—' }}</div>
                </div>
                <div class="space-y-1">
                    <div><strong>المسمى الوظيفي:</strong> {{ item.employee?.job_title || '—' }}</div>
                    <div><strong>العملة:</strong> {{ item.payroll?.currency }}</div>
                    <div><strong>رقم المسير:</strong> {{ item.payroll?.payroll_number }}</div>
                </div>
            </div>

            <!-- Earnings & Deductions -->
            <div class="grid grid-cols-2 gap-6 mb-6">
                <div>
                    <h3 class="font-bold text-sm mb-2 text-green-700 border-b pb-1">الاستحقاقات</h3>
                    <table class="w-full text-xs">
                        <tr class="border-b"><td class="py-1.5">الراتب الأساسي</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.basic_salary) }}</td></tr>
                        <tr class="border-b"><td class="py-1.5">بدل السكن</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.housing_allowance) }}</td></tr>
                        <tr class="border-b"><td class="py-1.5">بدل المواصلات</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.transport_allowance) }}</td></tr>
                        <tr class="border-b"><td class="py-1.5">بدلات أخرى</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.other_allowance) }}</td></tr>
                        <tr class="border-b"><td class="py-1.5">العمل الإضافي</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.overtime_amount) }}</td></tr>
                        <tr v-if="Number(item.bonus) > 0" class="border-b"><td class="py-1.5">مكافأة</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.bonus) }}</td></tr>
                        <tr class="font-bold bg-green-50"><td class="py-2">إجمالي الاستحقاقات</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.total_earnings) }}</td></tr>
                    </table>
                </div>
                <div>
                    <h3 class="font-bold text-sm mb-2 text-red-700 border-b pb-1">الخصومات</h3>
                    <table class="w-full text-xs">
                        <tr class="border-b"><td class="py-1.5">خصم التأخير</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.late_deduction) }}</td></tr>
                        <tr class="border-b"><td class="py-1.5">خصم الغياب</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.absence_deduction) }}</td></tr>
                        <tr class="border-b"><td class="py-1.5">إجازة غير مدفوعة</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.unpaid_leave_deduction) }}</td></tr>
                        <tr class="border-b"><td class="py-1.5">قسط سلفة</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.advance_deduction) }}</td></tr>
                        <tr class="border-b"><td class="py-1.5">مخالفات</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.penalty_deduction) }}</td></tr>
                        <tr class="font-bold bg-red-50"><td class="py-2">إجمالي الخصومات</td><td class="text-left font-mono" dir="ltr">{{ fmt(item.total_deductions) }}</td></tr>
                    </table>
                </div>
            </div>

            <!-- Net -->
            <div class="text-center py-4 bg-gray-100 rounded-lg mb-8">
                <div class="text-sm text-gray-500">صافي الراتب</div>
                <div class="text-3xl font-bold" dir="ltr">{{ fmt(item.net_salary) }} {{ item.payroll?.currency }}</div>
            </div>

            <div class="grid grid-cols-2 gap-8 mt-10 text-center text-xs text-gray-500">
                <div><div class="border-t border-gray-400 pt-2 mt-10">توقيع الموظف</div></div>
                <div><div class="border-t border-gray-400 pt-2 mt-10">مدير الموارد البشرية</div></div>
            </div>
        </div>
    </PrintLayout>
</template>

<script setup>
import PrintLayout from '@/Components/PrintLayout.vue';
const props = defineProps({ item: Object, month: Number, year: Number });
const monthNames = { 1:'يناير', 2:'فبراير', 3:'مارس', 4:'أبريل', 5:'مايو', 6:'يونيو', 7:'يوليو', 8:'أغسطس', 9:'سبتمبر', 10:'أكتوبر', 11:'نوفمبر', 12:'ديسمبر' };
const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 2 });
</script>
