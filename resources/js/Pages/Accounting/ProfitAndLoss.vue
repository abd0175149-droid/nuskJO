<template>
    <AppLayout>
        <template #header>قائمة الدخل</template>
        <div class="space-y-6">
            <!-- فلترة -->
            <div class="flex items-center gap-4 flex-wrap">
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium">من:</label>
                    <input type="date" v-model="filterFrom" @change="applyFilter" class="px-3 py-2 rounded-xl border text-sm" />
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium">إلى:</label>
                    <input type="date" v-model="filterTo" @change="applyFilter" class="px-3 py-2 rounded-xl border text-sm" />
                </div>
                <a :href="`/accounting/profit-loss/print?from=${filterFrom}&to=${filterTo}`" target="_blank" class="px-4 py-2 rounded-xl text-sm text-purple-600 border border-purple-200 hover:bg-purple-50">🖨️ طباعة</a>
            </div>

            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <!-- الإيرادات -->
                <div class="p-6 border-b">
                    <h3 class="text-lg font-bold text-green-700 mb-4">📈 الإيرادات</h3>
                    <table class="w-full">
                        <tbody>
                            <tr v-for="r in report.revenues" :key="r.id" class="border-b border-gray-50">
                                <td class="py-2 text-sm font-mono text-gray-400 w-16">{{ r.code }}</td>
                                <td class="py-2 text-sm">{{ r.name }}</td>
                                <td class="py-2 text-sm font-mono text-left font-bold text-green-600" dir="ltr">{{ fmt(r.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-green-50 dark:bg-green-900/10 font-bold">
                                <td colspan="2" class="py-3 px-2 text-sm">إجمالي الإيرادات</td>
                                <td class="py-3 text-sm font-mono text-left text-green-700" dir="ltr">{{ fmt(report.total_revenue) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- المصروفات -->
                <div class="p-6 border-b">
                    <h3 class="text-lg font-bold text-red-700 mb-4">📉 المصروفات</h3>
                    <table class="w-full">
                        <tbody>
                            <tr v-for="e in report.expenses" :key="e.id" class="border-b border-gray-50">
                                <td class="py-2 text-sm font-mono text-gray-400 w-16">{{ e.code }}</td>
                                <td class="py-2 text-sm">{{ e.name }}</td>
                                <td class="py-2 text-sm font-mono text-left font-bold text-red-600" dir="ltr">{{ fmt(e.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="bg-red-50 dark:bg-red-900/10 font-bold">
                                <td colspan="2" class="py-3 px-2 text-sm">إجمالي المصروفات</td>
                                <td class="py-3 text-sm font-mono text-left text-red-700" dir="ltr">{{ fmt(report.total_expenses) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- صافي الدخل -->
                <div class="p-6" :class="report.net_income >= 0 ? 'bg-green-100 dark:bg-green-900/20' : 'bg-red-100 dark:bg-red-900/20'">
                    <div class="flex items-center justify-between">
                        <span class="text-xl font-bold">{{ report.net_income >= 0 ? '✅ صافي الربح' : '⚠️ صافي الخسارة' }}</span>
                        <span class="text-2xl font-mono font-bold" :class="report.net_income >= 0 ? 'text-green-700' : 'text-red-700'" dir="ltr">
                            {{ fmt(Math.abs(report.net_income)) }} JOD
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ report: Object, filters: Object });
const filterFrom = ref(props.filters.from);
const filterTo = ref(props.filters.to);

const fmt = (n) => Number(n || 0).toLocaleString('en', { minimumFractionDigits: 3 });
const applyFilter = () => {
    router.get('/accounting/profit-loss', { from: filterFrom.value, to: filterTo.value }, { preserveState: true });
};
</script>
