<template>
  <AppLayout>
    <template #header>الزبائن المسافرون بتاريخ</template>
    <div class="space-y-6">
      <div class="flex flex-wrap items-end gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">تاريخ الرحلة</label>
          <input v-model="selectedDate" type="date" dir="ltr" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white" @change="load"/>
        </div>
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl px-5 py-3">
          <span class="text-xs text-gray-500">إجمالي المسافرين:</span>
          <span class="font-bold font-mono text-blue-700 dark:text-blue-400 mr-2" dir="ltr">{{ totalPax }}</span>
          <span class="text-xs text-gray-400 mx-2">·</span>
          <span class="text-xs text-gray-500">الفواتير:</span>
          <span class="font-bold font-mono text-blue-700 dark:text-blue-400 mr-1" dir="ltr">{{ invoices.length }}</span>
        </div>
      </div>

      <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
              <th class="px-4 py-3 text-right font-bold">الفاتورة</th>
              <th class="px-4 py-3 text-right font-bold">العميل</th>
              <th class="px-4 py-3 text-right font-bold hide-mobile">الهاتف</th>
              <th class="px-4 py-3 text-right font-bold hide-mobile">الوكلاء</th>
              <th class="px-4 py-3 text-center font-bold">عدد الأفراد</th>
              <th class="px-4 py-3 text-center font-bold hide-mobile">الإجمالي (د.أ)</th>
            </tr></thead>
            <tbody>
              <tr v-for="(i, idx) in invoices" :key="idx" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                <td class="px-4 py-2.5 text-right font-mono text-xs text-gold-700 font-bold" dir="ltr">{{ i.invoice_number }}</td>
                <td class="px-4 py-2.5 text-right text-gray-800 dark:text-gray-200 font-medium">{{ i.client || '—' }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs text-gray-500 hide-mobile" dir="ltr">{{ i.phone || '—' }}</td>
                <td class="px-4 py-2.5 text-right text-xs text-gray-600 dark:text-gray-400 hide-mobile">{{ i.agents.join('، ') || '—' }}</td>
                <td class="px-4 py-2.5 text-center font-mono font-bold" dir="ltr">{{ i.pax }}</td>
                <td class="px-4 py-2.5 text-center font-mono text-xs hide-mobile" dir="ltr">{{ fmt(i.total) }}</td>
              </tr>
              <tr v-if="!invoices.length"><td colspan="6" class="px-5 py-12 text-center text-gray-400">لا يوجد زبائن مسافرون في هذا التاريخ</td></tr>
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
const props = defineProps({ date: String, invoices: Array, totalPax: Number, filters: Object });
const selectedDate = ref(props.date);
const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const load = () => router.get('/reports/trip-date', { date: selectedDate.value }, { preserveState: true, replace: true });
</script>
