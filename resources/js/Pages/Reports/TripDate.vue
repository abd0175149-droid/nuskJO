<template>
  <AppLayout>
    <template #header>الزبائن المسافرون بتاريخ</template>
    <div class="space-y-6">
      <!-- الفلاتر -->
      <div class="flex flex-wrap items-end gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">تاريخ الرحلة</label>
          <input v-model="selectedDate" type="date" dir="ltr" :disabled="allDates" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white disabled:opacity-40 disabled:cursor-not-allowed" @change="load"/>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">&nbsp;</label>
          <label class="flex items-center gap-2 px-4 py-2.5 rounded-xl border cursor-pointer text-sm select-none" :class="allDates ? 'border-gold-400 bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-400 font-bold' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900'">
            <input type="checkbox" v-model="allDates" @change="load" class="rounded text-gold-500 focus:ring-gold-500"/>
            <span>📅 كل التواريخ</span>
          </label>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الموظف (منشئ الفاتورة)</label>
          <select v-model="selectedEmployee" @change="load" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white">
            <option value="">👥 كل الموظفين</option>
            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">حالة السداد</label>
          <select v-model="selectedRemaining" @change="load" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white">
            <option value="">الكل</option>
            <option value="with">عليها مبلغ متبقٍ</option>
            <option value="without">مسدّدة بالكامل</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">العرض</label>
          <select v-model="viewMode" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white">
            <option value="both">👥 العميل والوكيل</option>
            <option value="client">🧑 العميل فقط</option>
            <option value="agent">🏢 الوكيل فقط</option>
          </select>
        </div>

        <!-- ملخصات -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl px-5 py-3">
          <span class="text-xs text-gray-500">إجمالي المسافرين:</span>
          <span class="font-bold font-mono text-blue-700 dark:text-blue-400 mr-2" dir="ltr">{{ totalPax }}</span>
          <span class="text-xs text-gray-400 mx-2">·</span>
          <span class="text-xs text-gray-500">الفواتير:</span>
          <span class="font-bold font-mono text-blue-700 dark:text-blue-400 mr-1" dir="ltr">{{ invoices.length }}</span>
        </div>
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl px-5 py-3">
          <span class="text-xs text-gray-500">إجمالي المتبقي:</span>
          <span class="font-bold font-mono mr-1" :class="totalRemaining > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'" dir="ltr">{{ fmt(totalRemaining) }}</span>
          <span class="text-xs text-gray-400">د.أ</span>
        </div>
      </div>

      <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
              <th class="px-4 py-3 text-right font-bold">الفاتورة</th>
              <th class="px-4 py-3 text-center font-bold">تاريخ الرحلة</th>
              <th v-if="showClient" class="px-4 py-3 text-right font-bold">العميل</th>
              <th v-if="showClient" class="px-4 py-3 text-right font-bold hide-mobile">الهاتف</th>
              <th v-if="showAgent" class="px-4 py-3 text-right font-bold hide-mobile">الوكلاء</th>
              <th class="px-4 py-3 text-center font-bold">عدد الأفراد</th>
              <th class="px-4 py-3 text-center font-bold hide-mobile">الإجمالي (د.أ)</th>
              <th class="px-4 py-3 text-center font-bold">المتبقي (د.أ)</th>
              <th class="px-4 py-3 text-right font-bold hide-mobile">الموظف</th>
            </tr></thead>
            <tbody>
              <tr v-for="(i, idx) in invoices" :key="idx" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                <td class="px-4 py-2.5 text-right font-mono text-xs text-gold-700 font-bold" dir="ltr">{{ i.invoice_number }}</td>
                <td class="px-4 py-2.5 text-center font-mono text-xs text-gray-600 dark:text-gray-400" dir="ltr">{{ i.trip_date || '—' }}</td>
                <td v-if="showClient" class="px-4 py-2.5 text-right text-gray-800 dark:text-gray-200 font-medium">{{ i.client || '—' }}</td>
                <td v-if="showClient" class="px-4 py-2.5 text-right font-mono text-xs text-gray-500 hide-mobile" dir="ltr">{{ i.phone || '—' }}</td>
                <td v-if="showAgent" class="px-4 py-2.5 text-right text-xs text-gray-600 dark:text-gray-400 hide-mobile">{{ i.agents.join('، ') || '—' }}</td>
                <td class="px-4 py-2.5 text-center font-mono font-bold" dir="ltr">{{ i.pax }}</td>
                <td class="px-4 py-2.5 text-center font-mono text-xs hide-mobile" dir="ltr">{{ fmt(i.total) }}</td>
                <td class="px-4 py-2.5 text-center font-mono text-xs font-bold" :class="Number(i.remaining) > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'" dir="ltr">{{ fmt(i.remaining) }}</td>
                <td class="px-4 py-2.5 text-right text-xs text-gray-600 dark:text-gray-400 hide-mobile">{{ i.employee || '—' }}</td>
              </tr>
              <tr v-if="!invoices.length"><td colspan="9" class="px-5 py-12 text-center text-gray-400">لا يوجد زبائن مسافرون مطابقون</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
const props = defineProps({ date: String, invoices: Array, employees: { type: Array, default: () => [] }, totalPax: Number, totalRemaining: Number, filters: Object });
const selectedDate = ref(props.date);
const allDates = ref(!!props.filters?.all);
const selectedEmployee = ref(props.filters?.employee_id ?? '');
const selectedRemaining = ref(props.filters?.remaining ?? '');
// عرض العميل/الوكيل/كلاهما — تحكّم بالأعمدة فقط (فوري بلا إعادة تحميل)
const viewMode = ref('both');
const showClient = computed(() => viewMode.value !== 'agent');
const showAgent = computed(() => viewMode.value !== 'client');
const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const load = () => router.get('/reports/trip-date', {
  all: allDates.value ? 1 : undefined,
  date: allDates.value ? undefined : selectedDate.value,
  employee_id: selectedEmployee.value || undefined,
  remaining: selectedRemaining.value || undefined,
}, { preserveState: true, replace: true });
</script>
