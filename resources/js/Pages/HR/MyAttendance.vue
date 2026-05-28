<template>
    <AppLayout>
        <template #header>سجل حضوري</template>
        <div class="space-y-6">
            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center">
                    <div class="text-2xl font-bold text-green-600">{{ stats.present }}</div>
                    <div class="text-xs text-gray-500 mt-1">حضور</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center">
                    <div class="text-2xl font-bold text-yellow-600">{{ stats.late }}</div>
                    <div class="text-xs text-gray-500 mt-1">تأخير</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center">
                    <div class="text-2xl font-bold text-red-600">{{ stats.absent }}</div>
                    <div class="text-xs text-gray-500 mt-1">غياب</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center">
                    <div class="text-2xl font-bold text-blue-600">{{ stats.total_hours }}</div>
                    <div class="text-xs text-gray-500 mt-1">ساعات عمل</div>
                </div>
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm text-center">
                    <div class="text-2xl font-bold text-purple-600">{{ stats.overtime_hours }}</div>
                    <div class="text-xs text-gray-500 mt-1">ساعات إضافية</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex gap-3 flex-wrap">
                <select v-model="f.month" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                    <option v-for="m in 12" :key="m" :value="m">{{ monthNames[m] }}</option>
                </select>
                <select v-model="f.year" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                    <option v-for="y in [2025,2026,2027]" :key="y" :value="y">{{ y }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <th class="px-4 py-3 text-right font-bold">التاريخ</th>
                    <th class="px-4 py-3 text-right font-bold">وقت الدخول</th>
                    <th class="px-4 py-3 text-right font-bold">وقت الخروج</th>
                    <th class="px-4 py-3 text-right font-bold">الساعات</th>
                    <th class="px-4 py-3 text-right font-bold">تأخير</th>
                    <th class="px-4 py-3 text-right font-bold">إضافي</th>
                    <th class="px-4 py-3 text-right font-bold">الحالة</th>
                </tr></thead>
                <tbody>
                    <tr v-for="r in records" :key="r.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50">
                        <td class="px-4 py-3 text-right text-xs" dir="ltr">{{ r.date }}</td>
                        <td class="px-4 py-3 text-right text-xs font-mono text-green-600" dir="ltr">{{ r.check_in || '—' }}</td>
                        <td class="px-4 py-3 text-right text-xs font-mono text-red-600" dir="ltr">{{ r.check_out || '—' }}</td>
                        <td class="px-4 py-3 text-right text-xs font-mono" dir="ltr">{{ r.total_hours ? Number(r.total_hours).toFixed(1) : '—' }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ r.late_minutes ? r.late_minutes + ' د' : '—' }}</td>
                        <td class="px-4 py-3 text-right text-xs text-blue-600">{{ r.overtime_minutes ? r.overtime_minutes + ' د' : '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="{'bg-green-100 text-green-700':r.status==='present','bg-yellow-100 text-yellow-700':r.status==='late','bg-red-100 text-red-700':r.status==='absent'}">{{ {present:'حاضر',late:'متأخر',absent:'غائب'}[r.status] || r.status }}</span>
                        </td>
                    </tr>
                    <tr v-if="!records.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا يوجد سجلات</td></tr>
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
const props = defineProps({ records: Array, stats: Object, employee: Object, filters: Object });
const monthNames = {1:'يناير',2:'فبراير',3:'مارس',4:'أبريل',5:'مايو',6:'يونيو',7:'يوليو',8:'أغسطس',9:'سبتمبر',10:'أكتوبر',11:'نوفمبر',12:'ديسمبر'};
const f = ref({ month: props.filters?.month || new Date().getMonth()+1, year: props.filters?.year || new Date().getFullYear() });
const applyFilter = () => router.get('/hr/my-attendance', {...f.value}, { preserveState: true, replace: true });
</script>
