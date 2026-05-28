<template>
    <AppLayout>
        <template #header>طلباتي</template>
        <div class="space-y-8">
            <!-- Leaves -->
            <div>
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4">🏖️ طلبات الإجازة</h2>
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <th class="px-4 py-3 text-right font-bold">النوع</th>
                        <th class="px-4 py-3 text-right font-bold">من</th>
                        <th class="px-4 py-3 text-right font-bold">إلى</th>
                        <th class="px-4 py-3 text-right font-bold">الأيام</th>
                        <th class="px-4 py-3 text-right font-bold">الحالة</th>
                        <th class="px-4 py-3 text-right font-bold">التاريخ</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="l in leaves" :key="l.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50">
                            <td class="px-4 py-3 text-right text-xs">{{ l.leave_type?.name || '—' }}</td>
                            <td class="px-4 py-3 text-right text-xs" dir="ltr">{{ l.start_date }}</td>
                            <td class="px-4 py-3 text-right text-xs" dir="ltr">{{ l.end_date }}</td>
                            <td class="px-4 py-3 text-right text-xs">{{ l.days_count }} يوم</td>
                            <td class="px-4 py-3 text-right"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(l.status)">{{ statusLabel(l.status) }}</span></td>
                            <td class="px-4 py-3 text-right text-xs text-gray-400" dir="ltr">{{ l.created_at?.split('T')[0] }}</td>
                        </tr>
                        <tr v-if="!leaves.length"><td colspan="6" class="px-5 py-8 text-center text-gray-400">لا يوجد طلبات إجازة</td></tr>
                    </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <!-- Advances -->
            <div>
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4">💳 طلبات السلف</h2>
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <th class="px-4 py-3 text-right font-bold">الرقم</th>
                        <th class="px-4 py-3 text-right font-bold">المبلغ</th>
                        <th class="px-4 py-3 text-right font-bold">الأقساط</th>
                        <th class="px-4 py-3 text-right font-bold">المتبقي</th>
                        <th class="px-4 py-3 text-right font-bold">الحالة</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="a in advances" :key="a.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50">
                            <td class="px-4 py-3 text-right font-mono text-xs text-gold-700">{{ a.advance_number }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs" dir="ltr">{{ Number(a.amount).toLocaleString('en',{minimumFractionDigits:2}) }}</td>
                            <td class="px-4 py-3 text-right text-xs">{{ a.installments_count }} قسط</td>
                            <td class="px-4 py-3 text-right font-mono text-xs" dir="ltr" :class="Number(a.remaining_amount)>0?'text-red-600':'text-green-600'">{{ Number(a.remaining_amount).toLocaleString('en',{minimumFractionDigits:2}) }}</td>
                            <td class="px-4 py-3 text-right"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(a.status)">{{ statusLabel(a.status) }}</span></td>
                        </tr>
                        <tr v-if="!advances.length"><td colspan="5" class="px-5 py-8 text-center text-gray-400">لا يوجد طلبات سلف</td></tr>
                    </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Components/Layout/SmartLayout.vue';
defineProps({ leaves: Array, advances: Array, employee: Object });
const statusClass = (s) => ({ pending:'bg-yellow-100 text-yellow-700', approved:'bg-green-100 text-green-700', rejected:'bg-red-100 text-red-700' }[s] || '');
const statusLabel = (s) => ({ pending:'معلقة', approved:'معتمدة', rejected:'مرفوضة' }[s] || s);
</script>
