<template>
    <AppLayout>
        <template #header>تفاصيل مسير الرواتب — {{ payroll.payroll_number }}</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="text-xs text-gray-500 mb-1">الفترة</div>
                    <div class="text-lg font-bold text-gray-900 dark:text-white">{{ monthNames[payroll.month] }} {{ payroll.year }}</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="text-xs text-gray-500 mb-1">الموظفين</div>
                    <div class="text-lg font-bold text-blue-600">{{ payroll.employees_count }}</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="text-xs text-gray-500 mb-1">إجمالي الاستحقاقات</div>
                    <div class="text-lg font-bold text-green-600" dir="ltr">{{ fmt(payroll.total_basic) }}</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="text-xs text-gray-500 mb-1">إجمالي الخصومات</div>
                    <div class="text-lg font-bold text-red-600" dir="ltr">{{ fmt(payroll.total_deductions) }}</div>
                </div>
                <div class="p-4 rounded-xl bg-gradient-to-r from-gold-500 to-gold-400 shadow-sm">
                    <div class="text-xs text-black/60 mb-1">صافي الرواتب</div>
                    <div class="text-lg font-bold text-black" dir="ltr">{{ fmt(payroll.total_net) }} {{ payroll.currency }}</div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-3 flex-wrap">
                <button v-if="payroll.status==='draft' && can('payroll.generate')" @click="submitPayroll" class="px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-indigo-600 hover:bg-indigo-700">📤 تقديم للاعتماد</button>
                <button v-if="payroll.status==='pending' && can('payroll.approve')" @click="approvePayroll" class="px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-green-600 hover:bg-green-700">✅ اعتماد المسير</button>
                <button v-if="payroll.status==='pending' && can('payroll.reject')" @click="rejectPayroll" class="px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600">❌ رفض</button>
                <a v-if="payroll.status==='approved'" :href="'/payrolls/' + payroll.id + '/print'" target="_blank" class="px-5 py-2.5 rounded-xl font-bold text-sm text-gray-800 bg-gray-100 hover:bg-gray-200">🖨️ طباعة</a>
                <a v-if="payroll.status==='approved'" :href="'/payrolls/' + payroll.id + '/export-bank'" class="px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-teal-600 hover:bg-teal-700">🏦 تصدير بنكي CSV</a>
                <span class="px-3 py-2.5 rounded-xl text-xs font-bold" :class="statusClass(payroll.status)">{{ statusLabel(payroll.status) }}</span>
            </div>

            <!-- Items Table -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <th class="px-3 py-3 text-right font-bold text-xs">#</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">الموظف</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">القسم</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">الأساسي</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">سكن</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">مواصلات</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">أخرى</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">OT</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">تأخير</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">غياب</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">إجازة</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">سلفة</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">مخالفة</th>
                    <th class="px-3 py-3 text-right font-bold text-xs text-green-700">استحقاقات</th>
                    <th class="px-3 py-3 text-right font-bold text-xs text-red-700">خصومات</th>
                    <th class="px-3 py-3 text-right font-bold text-xs">الصافي</th>
                </tr></thead>
                <tbody>
                    <tr v-for="(item, idx) in payroll.items" :key="item.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td class="px-3 py-2.5 text-right text-xs text-gray-400">{{ idx + 1 }}</td>
                        <td class="px-3 py-2.5 text-right">
                            <div class="font-medium text-xs text-gray-900 dark:text-white">{{ item.employee?.user?.name }}</div>
                            <div class="text-[10px] text-gray-400">{{ item.employee?.employee_number }}</div>
                        </td>
                        <td class="px-3 py-2.5 text-right text-xs text-gray-600">{{ item.employee?.department?.name || '—' }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs" dir="ltr">{{ fmt(item.basic_salary) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs" dir="ltr">{{ fmt(item.housing_allowance) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs" dir="ltr">{{ fmt(item.transport_allowance) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs" dir="ltr">{{ fmt(item.other_allowance) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs text-blue-600" dir="ltr">{{ fmt(item.overtime_amount) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(item.late_deduction) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(item.absence_deduction) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(item.unpaid_leave_deduction) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(item.advance_deduction) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(item.penalty_deduction) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs font-bold text-green-600" dir="ltr">{{ fmt(item.total_earnings) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs font-bold text-red-600" dir="ltr">{{ fmt(item.total_deductions) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs font-bold text-gray-900 dark:text-white" dir="ltr">{{ fmt(item.net_salary) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="bg-gray-50 dark:bg-gray-800 font-bold border-t-2 border-gray-300">
                        <td colspan="3" class="px-3 py-3 text-right text-xs">الإجمالي</td>
                        <td class="px-3 py-3 text-right font-mono text-xs" dir="ltr">{{ fmt(totals.basic) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs" dir="ltr">{{ fmt(totals.housing) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs" dir="ltr">{{ fmt(totals.transport) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs" dir="ltr">{{ fmt(totals.other) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs text-blue-600" dir="ltr">{{ fmt(totals.ot) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(totals.late) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(totals.absence) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(totals.leave) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(totals.advance) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs text-red-500" dir="ltr">{{ fmt(totals.penalty) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs font-bold text-green-600" dir="ltr">{{ fmt(totals.earnings) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs font-bold text-red-600" dir="ltr">{{ fmt(totals.deductions) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-xs font-bold" dir="ltr">{{ fmt(totals.net) }}</td>
                    </tr>
                </tfoot>
                </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
const { can } = usePermissions();

const props = defineProps({ payroll: Object });
const monthNames = { 1:'يناير', 2:'فبراير', 3:'مارس', 4:'أبريل', 5:'مايو', 6:'يونيو', 7:'يوليو', 8:'أغسطس', 9:'سبتمبر', 10:'أكتوبر', 11:'نوفمبر', 12:'ديسمبر' };
const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 2 });

const totals = computed(() => {
    const items = props.payroll.items || [];
    return {
        basic: items.reduce((s, i) => s + Number(i.basic_salary), 0),
        housing: items.reduce((s, i) => s + Number(i.housing_allowance), 0),
        transport: items.reduce((s, i) => s + Number(i.transport_allowance), 0),
        other: items.reduce((s, i) => s + Number(i.other_allowance), 0),
        ot: items.reduce((s, i) => s + Number(i.overtime_amount), 0),
        late: items.reduce((s, i) => s + Number(i.late_deduction), 0),
        absence: items.reduce((s, i) => s + Number(i.absence_deduction), 0),
        leave: items.reduce((s, i) => s + Number(i.unpaid_leave_deduction), 0),
        advance: items.reduce((s, i) => s + Number(i.advance_deduction), 0),
        penalty: items.reduce((s, i) => s + Number(i.penalty_deduction), 0),
        earnings: items.reduce((s, i) => s + Number(i.total_earnings), 0),
        deductions: items.reduce((s, i) => s + Number(i.total_deductions), 0),
        net: items.reduce((s, i) => s + Number(i.net_salary), 0),
    };
});

const submitPayroll = () => { if (confirm('تقديم المسير للاعتماد؟')) router.post('/payrolls/' + props.payroll.id + '/submit', {}, { preserveScroll: true }); };
const approvePayroll = () => { if (confirm('اعتماد المسير؟ سيتم خصم الأقساط والمخالفات تلقائياً.')) router.post('/payrolls/' + props.payroll.id + '/approve', {}, { preserveScroll: true }); };
const rejectPayroll = () => { const r = prompt('سبب الرفض:'); if (r !== null) router.post('/payrolls/' + props.payroll.id + '/reject', { rejection_reason: r }, { preserveScroll: true }); };

const statusClass = (s) => ({ draft: 'bg-gray-100 text-gray-600', pending: 'bg-yellow-100 text-yellow-700', approved: 'bg-green-100 text-green-700', rejected: 'bg-red-100 text-red-700' }[s] || '');
const statusLabel = (s) => ({ draft: 'مسودة', pending: 'معلقة', approved: 'معتمدة', rejected: 'مرفوضة' }[s] || s);
</script>
