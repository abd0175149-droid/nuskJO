<template>
    <AppLayout>
        <template #header>قسائم الراتب</template>

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between border-b pb-4 dark:border-gray-800">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gold-100 dark:bg-gold-900/30 text-gold-700 dark:text-gold-400 rounded-full flex items-center justify-center text-xl font-bold">
                        {{ employee.user?.name?.charAt(0) }}
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">{{ employee.user?.name }}</h2>
                        <p class="text-sm text-gray-500">{{ employee.employee_number }}</p>
                    </div>
                </div>
            </div>

            <!-- List -->
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                <div class="p-6">
                    <h3 class="font-bold text-lg mb-4 text-gray-800 dark:text-gray-200">سجل الرواتب</h3>
                    <div v-if="!payrollItems.length" class="text-center py-12 text-gray-400">
                        لا توجد قسائم راتب متاحة.
                    </div>
                    <div v-else class="space-y-4">
                        <div v-for="item in payrollItems" :key="item.id" 
                             class="flex items-center justify-between p-4 rounded-xl border dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-lg flex items-center justify-center text-2xl" 
                                     :class="item.net_salary > 0 ? 'bg-green-100 text-green-600 dark:bg-green-900/30' : 'bg-gray-100 text-gray-500'">
                                    💰
                                </div>
                                <div>
                                    <p class="font-bold text-gray-800 dark:text-gray-200">
                                        راتب شهر {{ item.payroll?.month }} / {{ item.payroll?.year }}
                                    </p>
                                    <p class="text-xs text-gray-500 font-mono mt-1" dir="ltr">
                                        {{ fmt(item.net_salary, 2) }} {{ item.payroll?.currency }}
                                    </p>
                                </div>
                            </div>
                            <div>
                                <a :href="`/payslip/${employee.id}/${item.payroll?.month}/${item.payroll?.year}`" target="_blank"
                                   class="px-4 py-2 bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 rounded-lg text-sm font-bold hover:bg-blue-100 transition-colors">
                                    عرض القسيمة
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import AppLayout from '@/Components/Layout/SmartLayout.vue';

const props = defineProps({
    payrollItems: Array,
    employee: Object
});

const fmt = (v, d = 2) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: d, maximumFractionDigits: d });
</script>
