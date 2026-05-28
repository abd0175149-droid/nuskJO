<template>
    <AppLayout>
        <template #header>تقرير الحضور</template>
        <div class="space-y-6">
            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                <div class="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center"><div class="text-xl font-bold text-gray-800 dark:text-white">{{ stats.total_records }}</div><div class="text-[10px] text-gray-500">إجمالي</div></div>
                <div class="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center"><div class="text-xl font-bold text-green-600">{{ stats.present }}</div><div class="text-[10px] text-gray-500">حضور</div></div>
                <div class="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center"><div class="text-xl font-bold text-yellow-600">{{ stats.late }}</div><div class="text-[10px] text-gray-500">تأخير</div></div>
                <div class="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center"><div class="text-xl font-bold text-red-600">{{ stats.absent }}</div><div class="text-[10px] text-gray-500">غياب</div></div>
                <div class="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center"><div class="text-xl font-bold text-blue-600">{{ stats.total_hours }}</div><div class="text-[10px] text-gray-500">ساعات</div></div>
                <div class="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center"><div class="text-xl font-bold text-purple-600">{{ stats.total_overtime }}</div><div class="text-[10px] text-gray-500">OT ساعات</div></div>
                <div class="p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center"><div class="text-xl font-bold text-orange-600">{{ stats.total_late_minutes }}</div><div class="text-[10px] text-gray-500">دقائق تأخير</div></div>
            </div>

            <!-- Filters -->
            <div class="flex gap-3 flex-wrap">
                <select v-model="f.month" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter"><option v-for="m in 12" :key="m" :value="m">{{ monthNames[m] }}</option></select>
                <select v-model="f.year" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter"><option v-for="y in [2025,2026,2027]" :key="y" :value="y">{{ y }}</option></select>
                <select v-model="f.employee_id" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter"><option value="">كل الموظفين</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select>
                <select v-model="f.department_id" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter"><option value="">كل الأقسام</option><option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option></select>
            </div>

            <!-- Employee Summary -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <h3 class="px-4 py-3 font-bold text-sm bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300">ملخص الموظفين</h3>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <th class="px-4 py-2 text-right font-bold text-xs">الموظف</th>
                    <th class="px-4 py-2 text-right font-bold text-xs">الرقم</th>
                    <th class="px-4 py-2 text-right font-bold text-xs">القسم</th>
                    <th class="px-4 py-2 text-right font-bold text-xs">حضور</th>
                    <th class="px-4 py-2 text-right font-bold text-xs">تأخير</th>
                    <th class="px-4 py-2 text-right font-bold text-xs">غياب</th>
                    <th class="px-4 py-2 text-right font-bold text-xs">ساعات</th>
                    <th class="px-4 py-2 text-right font-bold text-xs">OT</th>
                    <th class="px-4 py-2 text-right font-bold text-xs">دقائق تأخير</th>
                </tr></thead>
                <tbody>
                    <tr v-for="s in employeeSummary" :key="s.employee_id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50">
                        <td class="px-4 py-2 text-right text-xs font-medium">{{ s.name }}</td>
                        <td class="px-4 py-2 text-right text-xs font-mono text-gray-400">{{ s.employee_number }}</td>
                        <td class="px-4 py-2 text-right text-xs">{{ s.department }}</td>
                        <td class="px-4 py-2 text-right text-xs text-green-600 font-bold">{{ s.present_days }}</td>
                        <td class="px-4 py-2 text-right text-xs text-yellow-600 font-bold">{{ s.late_days }}</td>
                        <td class="px-4 py-2 text-right text-xs text-red-600 font-bold">{{ s.absent_days }}</td>
                        <td class="px-4 py-2 text-right text-xs">{{ s.total_hours }}</td>
                        <td class="px-4 py-2 text-right text-xs text-blue-600">{{ s.overtime_hours }}</td>
                        <td class="px-4 py-2 text-right text-xs text-orange-600">{{ s.late_minutes }}</td>
                    </tr>
                    <tr v-if="!employeeSummary.length"><td colspan="9" class="px-5 py-8 text-center text-gray-400">لا يوجد بيانات</td></tr>
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
const props = defineProps({ records: Array, employeeSummary: Array, stats: Object, employees: Array, departments: Array, filters: Object });
const monthNames = {1:'يناير',2:'فبراير',3:'مارس',4:'أبريل',5:'مايو',6:'يونيو',7:'يوليو',8:'أغسطس',9:'سبتمبر',10:'أكتوبر',11:'نوفمبر',12:'ديسمبر'};
const f = ref({ month: props.filters?.month, year: props.filters?.year, employee_id: props.filters?.employee_id || '', department_id: props.filters?.department_id || '' });
const applyFilter = () => router.get('/hr/reports/attendance', {...f.value}, { preserveState: true, replace: true });
</script>
