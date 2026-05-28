<template>
    <AppLayout>
        <template #header>ميزان المراجعة</template>
        <div class="space-y-6">
            <!-- فلاتر -->
            <div class="flex flex-wrap items-end gap-4 p-4 rounded-xl border bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">من تاريخ</label>
                    <input v-model="from" type="date" class="px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white" />
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">إلى تاريخ</label>
                    <input v-model="to" type="date" class="px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white" />
                </div>
                <button @click="applyFilter" class="px-5 py-2 rounded-lg font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">🔍 عرض</button>
                <a :href="`/accounting/trial-balance/print?from=${from}&to=${to}`" target="_blank" class="px-4 py-2 rounded-lg text-sm text-purple-600 border border-purple-200 hover:bg-purple-50">🖨️ طباعة</a>
            </div>

            <!-- ملخص النقدية -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="p-4 rounded-xl border bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/20 dark:to-green-900/10 border-green-200 dark:border-green-800">
                    <p class="text-xs text-green-600 font-bold mb-1">💵 الصندوق (الكاش)</p>
                    <p class="text-xl font-bold font-mono text-green-700" dir="ltr">{{ fmt(cashSummary.cash) }} <span class="text-sm">JOD</span></p>
                </div>
                <div class="p-4 rounded-xl border bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-900/10 border-blue-200 dark:border-blue-800">
                    <p class="text-xs text-blue-600 font-bold mb-1">🏦 البنك</p>
                    <p class="text-xl font-bold font-mono text-blue-700" dir="ltr">{{ fmt(cashSummary.bank) }} <span class="text-sm">JOD</span></p>
                </div>
                <div class="p-4 rounded-xl border bg-gradient-to-br from-amber-50 to-amber-100 dark:from-amber-900/20 dark:to-amber-900/10 border-amber-200 dark:border-amber-800">
                    <p class="text-xs text-amber-600 font-bold mb-1">📋 شيكات تحت التحصيل</p>
                    <p class="text-xl font-bold font-mono text-amber-700" dir="ltr">{{ fmt(cashSummary.checks) }} <span class="text-sm">JOD</span></p>
                </div>
            </div>

            <!-- جدول الميزان -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                            <th class="px-4 py-3 text-right font-bold" rowspan="2">الكود</th>
                            <th class="px-4 py-3 text-right font-bold" rowspan="2">اسم الحساب</th>
                            <th class="px-4 py-3 text-center font-bold border-b border-gray-200 dark:border-gray-700" colspan="2">رصيد أول المدة</th>
                            <th class="px-4 py-3 text-center font-bold border-b border-gray-200 dark:border-gray-700" colspan="2">حركات الفترة</th>
                            <th class="px-4 py-3 text-center font-bold border-b border-gray-200 dark:border-gray-700" colspan="2">رصيد آخر المدة</th>
                        </tr>
                        <tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 dark:text-gray-500 text-xs">
                            <th class="px-3 py-2 text-center">مدين</th>
                            <th class="px-3 py-2 text-center">دائن</th>
                            <th class="px-3 py-2 text-center">مدين</th>
                            <th class="px-3 py-2 text-center">دائن</th>
                            <th class="px-3 py-2 text-center">مدين</th>
                            <th class="px-3 py-2 text-center">دائن</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in accounts" :key="a.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td class="px-4 py-2.5 text-right font-mono text-xs text-gold-700 font-bold">{{ a.code }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-800 dark:text-gray-200 text-xs font-medium">{{ a.name }}</td>
                            <td class="px-3 py-2.5 text-center font-mono text-xs" dir="ltr">{{ a.opening_debit > 0 ? fmt(a.opening_debit) : '—' }}</td>
                            <td class="px-3 py-2.5 text-center font-mono text-xs" dir="ltr">{{ a.opening_credit > 0 ? fmt(a.opening_credit) : '—' }}</td>
                            <td class="px-3 py-2.5 text-center font-mono text-xs text-red-600 font-bold" dir="ltr">{{ a.period_debit > 0 ? fmt(a.period_debit) : '—' }}</td>
                            <td class="px-3 py-2.5 text-center font-mono text-xs text-green-600 font-bold" dir="ltr">{{ a.period_credit > 0 ? fmt(a.period_credit) : '—' }}</td>
                            <td class="px-3 py-2.5 text-center font-mono text-xs font-bold" :class="a.closing_debit > a.closing_credit ? 'text-red-600' : ''" dir="ltr">{{ a.closing_debit > 0 ? fmt(a.closing_debit) : '—' }}</td>
                            <td class="px-3 py-2.5 text-center font-mono text-xs font-bold" :class="a.closing_credit > a.closing_debit ? 'text-green-600' : ''" dir="ltr">{{ a.closing_credit > 0 ? fmt(a.closing_credit) : '—' }}</td>
                        </tr>
                        <tr v-if="!accounts?.length">
                            <td colspan="8" class="px-5 py-12 text-center text-gray-400">لا يوجد حركات مالية في هذه الفترة</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="accounts?.length" class="bg-gray-50 dark:bg-gray-800/50 font-bold">
                        <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                            <td class="px-4 py-3 text-right font-bold text-gray-800 dark:text-gray-100" colspan="2">المجموع</td>
                            <td class="px-3 py-3 text-center font-mono text-xs" dir="ltr">{{ fmt(totals.opening_debit) }}</td>
                            <td class="px-3 py-3 text-center font-mono text-xs" dir="ltr">{{ fmt(totals.opening_credit) }}</td>
                            <td class="px-3 py-3 text-center font-mono text-xs text-red-600" dir="ltr">{{ fmt(totals.period_debit) }}</td>
                            <td class="px-3 py-3 text-center font-mono text-xs text-green-600" dir="ltr">{{ fmt(totals.period_credit) }}</td>
                            <td class="px-3 py-3 text-center font-mono text-xs" dir="ltr">{{ fmt(totals.closing_debit) }}</td>
                            <td class="px-3 py-3 text-center font-mono text-xs" dir="ltr">{{ fmt(totals.closing_credit) }}</td>
                        </tr>
                        <tr>
                            <td colspan="8" class="px-4 py-2 text-center">
                                <span v-if="Math.abs(totals.closing_debit - totals.closing_credit) < 0.01" class="text-green-600 font-bold text-xs">✅ الميزان متوازن</span>
                                <span v-else class="text-red-600 font-bold text-xs">⚠️ الميزان غير متوازن — فرق: {{ fmt(Math.abs(totals.closing_debit - totals.closing_credit)) }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ accounts: Array, filters: Object, cashSummary: Object });
const from = ref(props.filters?.from || '');
const to = ref(props.filters?.to || '');

const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const totals = computed(() => {
    const t = { opening_debit: 0, opening_credit: 0, period_debit: 0, period_credit: 0, closing_debit: 0, closing_credit: 0 };
    (props.accounts || []).forEach(a => {
        t.opening_debit += a.opening_debit;
        t.opening_credit += a.opening_credit;
        t.period_debit += a.period_debit;
        t.period_credit += a.period_credit;
        t.closing_debit += a.closing_debit;
        t.closing_credit += a.closing_credit;
    });
    return t;
});

const applyFilter = () => {
    router.get('/accounting/trial-balance', { from: from.value, to: to.value }, { preserveState: true, replace: true });
};

</script>
