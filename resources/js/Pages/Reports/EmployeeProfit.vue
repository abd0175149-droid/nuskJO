<template>
  <AppLayout>
    <template #header>أرباح الموظفين</template>
    <div class="space-y-6">
      <div class="flex flex-wrap items-end gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">من تاريخ</label>
          <input v-model="from" type="date" dir="ltr" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"/>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">إلى تاريخ</label>
          <input v-model="to" type="date" dir="ltr" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"/>
        </div>
        <button @click="load" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">🔍 عرض</button>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl px-5 py-3 ms-auto">
          <span class="text-xs text-gray-500">إجمالي الأرباح:</span>
          <span class="font-bold font-mono text-green-700 dark:text-green-400 mr-2" dir="ltr">{{ fmt(totalProfit) }} JOD</span>
        </div>
      </div>

      <p class="text-xs text-gray-400">الربح = مجموع أرباح الفواتير المعتمدة لعملاء الموظف خلال الفترة. تقرير مستقل لا يؤثر على قائمة الدخل.</p>

      <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
              <th class="px-4 py-3 text-right font-bold">#</th>
              <th class="px-4 py-3 text-right font-bold">الموظف</th>
              <th class="px-4 py-3 text-center font-bold">العملاء</th>
              <th class="px-4 py-3 text-center font-bold">الفواتير</th>
              <th class="px-4 py-3 text-center font-bold hide-mobile">المبيع (د.أ)</th>
              <th class="px-4 py-3 text-center font-bold">الربح (د.أ)</th>
            </tr></thead>
            <tbody>
              <tr v-for="(r, i) in rows" :key="i" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30" :class="{'opacity-60': !r.employee_id}">
                <td class="px-4 py-2.5 text-right font-mono text-xs text-gray-400">{{ i + 1 }}</td>
                <td class="px-4 py-2.5 text-right font-medium text-gray-800 dark:text-gray-200">{{ r.employee }}<span v-if="r.employee_number" class="text-xs text-gray-400 font-mono mr-2" dir="ltr">{{ r.employee_number }}</span></td>
                <td class="px-4 py-2.5 text-center font-mono" dir="ltr">{{ r.clients }}</td>
                <td class="px-4 py-2.5 text-center font-mono" dir="ltr">{{ r.invoices }}</td>
                <td class="px-4 py-2.5 text-center font-mono text-xs hide-mobile" dir="ltr">{{ fmt(r.sell_jod) }}</td>
                <td class="px-4 py-2.5 text-center font-mono font-bold" :class="r.profit_jod >= 0 ? 'text-green-600' : 'text-red-600'" dir="ltr">{{ fmt(r.profit_jod) }}</td>
              </tr>
              <tr v-if="!rows.length"><td colspan="6" class="px-5 py-12 text-center text-gray-400">لا توجد بيانات في هذه الفترة</td></tr>
            </tbody>
            <tfoot v-if="rows.length" class="bg-gray-50 dark:bg-gray-800/50 font-bold">
              <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                <td colspan="5" class="px-4 py-3 text-right">الإجمالي</td>
                <td class="px-4 py-3 text-center font-mono text-green-700" dir="ltr">{{ fmt(totalProfit) }}</td>
              </tr>
            </tfoot>
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
const props = defineProps({ rows: Array, totalProfit: Number, filters: Object });
const from = ref(props.filters?.from || '');
const to = ref(props.filters?.to || '');
const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const load = () => router.get('/reports/employee-profit', { from: from.value, to: to.value }, { preserveState: true, replace: true });
</script>
