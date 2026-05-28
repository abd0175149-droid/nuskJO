<template>
    <AppLayout>
        <template #header>ذمم العملاء</template>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <input v-model="search" type="text" placeholder="بحث..." class="w-64 px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500"/>
                <div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-3">
                    <span class="text-xs text-gray-500">الإجمالي:</span>
                    <span class="font-bold font-mono text-blue-700 mr-2" dir="ltr">{{ Number(total).toLocaleString('en',{minimumFractionDigits:3}) }} JOD</span>
                </div>
            </div>
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 text-gray-600">
                        <th class="px-5 py-3 text-right font-bold">الاسم</th>
                        <th class="px-5 py-3 text-right font-bold">الكود</th>
                        <th class="px-5 py-3 text-right font-bold">الرصيد (الذمة)</th>
                        <th class="px-5 py-3 text-right font-bold">الحد الائتماني</th>
                        <th class="px-5 py-3 text-right font-bold">التجاوز</th>
                        <th class="px-5 py-3 text-right font-bold">الحالة</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="c in filteredClients" :key="c.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-gray-100">{{ c.name }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-gold-700">{{ c.code }}</td>
                            <td class="px-5 py-3 font-bold font-mono" dir="ltr" :class="parseFloat(c.balance_jod)>=0?'text-green-600':'text-red-600'">{{ Math.abs(Number(c.balance_jod)).toLocaleString('en',{minimumFractionDigits:3}) }} {{ c.currency||'JOD' }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-gray-500" dir="ltr">{{ Number(c.credit_limit_jod||0).toLocaleString('en',{minimumFractionDigits:3}) }}</td>
                            <td class="px-5 py-3"><span v-if="c.credit_limit_jod && parseFloat(c.balance_jod) > parseFloat(c.credit_limit_jod)" class="px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700">⚠️ متجاوز</span><span v-else class="text-xs text-gray-400">—</span></td>
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="c.is_active?'bg-green-100 text-green-700':'bg-red-100 text-red-700'">{{ c.is_active?'نشط':'معطل' }}</span></td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
const props = defineProps({ clients: Array, total: Number, filters: Object });
const search = ref('');
const filteredClients = computed(() => {
    if (!search.value) return props.clients;
    const s = search.value.toLowerCase();
    return props.clients.filter(c => c.name.toLowerCase().includes(s) || c.code.toLowerCase().includes(s));
});
</script>
