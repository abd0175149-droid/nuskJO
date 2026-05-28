<template>
    <AppLayout>
        <template #header>أرصدة الوكلاء</template>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <input v-model="search" type="text" placeholder="بحث..." class="w-64 px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                <div class="bg-green-50 border border-green-200 rounded-xl px-5 py-3">
                    <span class="text-xs text-gray-500">الإجمالي:</span>
                    <span class="font-bold font-mono text-green-700 mr-2" dir="ltr">{{ Number(total).toLocaleString('en',{minimumFractionDigits:2}) }} SAR</span>
                </div>
            </div>
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 text-gray-600">
                        <th class="px-5 py-3 text-right font-bold">الاسم</th>
                        <th class="px-5 py-3 text-right font-bold">الكود</th>
                        <th class="px-5 py-3 text-right font-bold">الدولة</th>
                        <th class="px-5 py-3 text-right font-bold">الرصيد</th>
                        <th class="px-5 py-3 text-right font-bold">الحالة</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="a in filteredAgents" :key="a.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-gray-100">{{ a.name }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-gold-700">{{ a.code }}</td>
                            <td class="px-5 py-3 text-xs">{{ {JO:'🇯🇴',SA:'🇸🇦'}[a.country]||'' }}</td>
                            <td class="px-5 py-3 font-bold font-mono" dir="ltr" :class="parseFloat(a.balance_sar)>=0?'text-green-600':'text-red-600'">{{ Math.abs(Number(a.balance_sar)).toLocaleString('en',{minimumFractionDigits:2}) }} {{ a.currency||'SAR' }}</td>
                            <td class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="a.is_active?'bg-green-100 text-green-700':'bg-red-100 text-red-700'">{{ a.is_active?'نشط':'معطل' }}</span></td>
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
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
const props = defineProps({ agents: Array, total: Number, filters: Object });
const search = ref(props.filters?.search||'');
let t=null;
const filteredAgents = computed(() => {
    if (!search.value) return props.agents;
    const s = search.value.toLowerCase();
    return props.agents.filter(a => a.name.toLowerCase().includes(s) || a.code.toLowerCase().includes(s));
});
const debounceSearch = () => {};
</script>
