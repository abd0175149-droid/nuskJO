<template>
    <AppLayout>
        <template #header>الميزانية العمومية</template>
        <div class="space-y-6">
            <!-- فلتر التاريخ -->
            <div class="flex items-center gap-4">
                <label class="text-sm font-medium">كما في تاريخ:</label>
                <input type="date" v-model="filterDate" @change="applyFilter" class="px-3 py-2 rounded-xl border text-sm" />
                <a :href="`/accounting/balance-sheet/print?as_of=${filterDate}`" target="_blank" class="px-4 py-2 rounded-xl text-sm text-purple-600 border border-purple-200 hover:bg-purple-50">🖨️ طباعة</a>
            </div>

            <!-- مؤشر التوازن -->
            <div class="p-4 rounded-xl border text-sm" :class="report.is_balanced ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'">
                {{ report.is_balanced ? '✅ المعادلة المحاسبية متوازنة' : '⚠️ المعادلة المحاسبية غير متوازنة!' }}
                — الأصول: {{ fmt(report.total_assets) }} | الالتزامات + الملكية: {{ fmt(report.total_liabilities_equity) }}
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- الأصول -->
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="p-4 bg-blue-50 dark:bg-blue-900/10 border-b">
                        <h3 class="text-lg font-bold text-blue-700">📂 الأصول</h3>
                    </div>
                    <div class="p-4">
                        <table class="w-full">
                            <tbody>
                                <tr v-for="a in report.assets" :key="a.id" class="border-b border-gray-50">
                                    <td class="py-2 text-sm font-mono text-gray-400 w-16">{{ a.code }}</td>
                                    <td class="py-2 text-sm">{{ a.name }}</td>
                                    <td class="py-2 text-sm font-mono text-left font-bold" dir="ltr">{{ fmt(a.balance) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-blue-50 dark:bg-blue-900/10 font-bold">
                                    <td colspan="2" class="py-3 px-2 text-sm">إجمالي الأصول</td>
                                    <td class="py-3 text-sm font-mono text-left text-blue-700" dir="ltr">{{ fmt(report.total_assets) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- الالتزامات + حقوق الملكية -->
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="p-4 bg-orange-50 dark:bg-orange-900/10 border-b">
                        <h3 class="text-lg font-bold text-orange-700">📋 الالتزامات</h3>
                    </div>
                    <div class="p-4">
                        <table class="w-full">
                            <tbody>
                                <tr v-for="l in report.liabilities" :key="l.id" class="border-b border-gray-50">
                                    <td class="py-2 text-sm font-mono text-gray-400 w-16">{{ l.code }}</td>
                                    <td class="py-2 text-sm">{{ l.name }}</td>
                                    <td class="py-2 text-sm font-mono text-left font-bold" dir="ltr">{{ fmt(l.balance) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-orange-50 dark:bg-orange-900/10 font-bold">
                                    <td colspan="2" class="py-3 px-2 text-sm">إجمالي الالتزامات</td>
                                    <td class="py-3 text-sm font-mono text-left text-orange-700" dir="ltr">{{ fmt(report.total_liabilities) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="p-4 bg-purple-50 dark:bg-purple-900/10 border-t">
                        <h3 class="text-lg font-bold text-purple-700 mb-3">💰 حقوق الملكية</h3>
                        <table class="w-full">
                            <tbody>
                                <tr v-for="e in report.equity" :key="e.id" class="border-b border-gray-50">
                                    <td class="py-2 text-sm font-mono text-gray-400 w-16">{{ e.code }}</td>
                                    <td class="py-2 text-sm">{{ e.name }}</td>
                                    <td class="py-2 text-sm font-mono text-left font-bold" dir="ltr">{{ fmt(e.balance) }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-purple-100 dark:bg-purple-900/20 font-bold">
                                    <td colspan="2" class="py-3 px-2 text-sm">إجمالي حقوق الملكية</td>
                                    <td class="py-3 text-sm font-mono text-left text-purple-700" dir="ltr">{{ fmt(report.total_equity) }}</td>
                                </tr>
                                <tr class="bg-gray-100 dark:bg-gray-800 font-bold text-lg">
                                    <td colspan="2" class="py-3 px-2">المجموع</td>
                                    <td class="py-3 font-mono text-left" dir="ltr">{{ fmt(report.total_liabilities_equity) }}</td>
                                </tr>
                            </tfoot>
                        </table>
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
const filterDate = ref(props.filters.as_of);

const fmt = (n) => Number(n || 0).toLocaleString('en', { minimumFractionDigits: 3 });
const applyFilter = () => {
    router.get('/accounting/balance-sheet', { as_of: filterDate.value }, { preserveState: true });
};
</script>
