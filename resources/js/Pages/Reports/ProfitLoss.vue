<template>
    <AppLayout>
        <template #header>الأرباح والخسائر</template>
        <div class="space-y-6">
            <form @submit.prevent="applyFilter" class="flex flex-wrap items-end gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">من</label><input v-model="from" type="date" dir="ltr" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                <div><label class="block text-sm font-medium text-gray-700 mb-1">إلى</label><input v-model="to" type="date" dir="ltr" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                <button type="submit" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400">🔍 عرض</button>
            </form>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- الإيرادات -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                    <h4 class="font-bold text-sm text-green-700 mb-4">📈 الإيرادات</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center"><span class="text-sm text-gray-600">الفواتير (JOD)</span><span class="font-bold font-mono text-green-600" dir="ltr">{{ fmt(data.invoices_jod,3) }}</span></div>
                        <div class="flex justify-between items-center"><span class="text-sm text-gray-600">سندات القبض (JOD)</span><span class="font-bold font-mono text-blue-600" dir="ltr">{{ fmt(data.receipts_jod,3) }}</span></div>
                        <hr/>
                        <div class="flex justify-between items-center"><span class="text-sm font-bold text-gray-800 dark:text-gray-100">إجمالي الإيرادات</span><span class="font-bold font-mono text-green-700 text-lg" dir="ltr">{{ fmt(data.invoices_jod + data.receipts_jod,3) }} JOD</span></div>
                    </div>
                </div>
                <!-- المصاريف -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                    <h4 class="font-bold text-sm text-red-700 mb-4">📉 المصروفات</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center"><span class="text-sm text-gray-600">سندات الصرف (JOD)</span><span class="font-bold font-mono text-orange-600" dir="ltr">{{ fmt(data.disbursements_jod,3) }}</span></div>
                        <hr/>
                        <div class="flex justify-between items-center"><span class="text-sm font-bold text-gray-800 dark:text-gray-100">إجمالي التكاليف (JOD)</span><span class="font-bold font-mono text-red-700 text-lg" dir="ltr">{{ fmt(data.disbursements_jod,3) }} JOD</span></div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
const props = defineProps({ data: Object, filters: Object });
const from = ref(props.filters?.from||'');
const to = ref(props.filters?.to||'');
const fmt = (v, d) => Number(v||0).toLocaleString('en',{minimumFractionDigits:d,maximumFractionDigits:d});
const applyFilter = () => { router.get('/reports/profit-loss',{from:from.value,to:to.value},{preserveState:true,replace:true}); };
</script>
