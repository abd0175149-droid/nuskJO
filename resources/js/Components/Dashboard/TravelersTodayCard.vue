<template>
    <div class="dash-card p-5">
        <!-- الرأس -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="flex items-center gap-2">
                <h4 class="font-bold text-sm text-gray-700 dark:text-gray-200">✈️ مسافرو اليوم</h4>
                <span class="font-mono text-xs text-gray-400" dir="ltr">{{ date }}</span>
                <span v-if="loading" class="text-xs text-gold-500 animate-pulse">🔄</span>
            </div>
            <div class="flex items-center gap-3 text-xs">
                <span class="text-gray-500">المسافرون: <strong class="font-mono text-blue-600 dark:text-blue-400" dir="ltr">{{ totalPax }}</strong></span>
                <span class="text-gray-400">·</span>
                <span class="text-gray-500">المتبقي: <strong class="font-mono" :class="totalRemaining > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'" dir="ltr">{{ fmt(totalRemaining) }}</strong></span>
                <a href="/reports/trip-date" class="text-gold-600 hover:underline font-bold">عرض الكل ←</a>
            </div>
        </div>

        <!-- الجدول -->
        <div class="overflow-x-auto -mx-1">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400 text-xs border-b border-gray-100 dark:border-gray-800">
                        <th class="px-3 py-2 text-right font-bold">الفاتورة</th>
                        <th class="px-3 py-2 text-right font-bold">العميل</th>
                        <th class="px-3 py-2 text-right font-bold hide-mobile">الهاتف</th>
                        <th class="px-3 py-2 text-right font-bold hide-mobile">الوكلاء</th>
                        <th class="px-3 py-2 text-center font-bold">الأفراد</th>
                        <th class="px-3 py-2 text-center font-bold hide-mobile">الإجمالي</th>
                        <th class="px-3 py-2 text-center font-bold">المتبقي</th>
                        <th class="px-3 py-2 text-right font-bold hide-mobile">الموظف</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(i, idx) in rows" :key="idx" class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td class="px-3 py-2 text-right font-mono text-xs text-gold-700 font-bold" dir="ltr">{{ i.invoice_number }}</td>
                        <td class="px-3 py-2 text-right text-gray-800 dark:text-gray-200 font-medium">{{ i.client || '—' }}</td>
                        <td class="px-3 py-2 text-right font-mono text-xs text-gray-500 hide-mobile" dir="ltr">{{ i.phone || '—' }}</td>
                        <td class="px-3 py-2 text-right text-xs text-gray-600 dark:text-gray-400 hide-mobile">{{ (i.agents || []).join('، ') || '—' }}</td>
                        <td class="px-3 py-2 text-center font-mono font-bold" dir="ltr">{{ i.pax }}</td>
                        <td class="px-3 py-2 text-center font-mono text-xs hide-mobile" dir="ltr">{{ fmt(i.total) }}</td>
                        <td class="px-3 py-2 text-center font-mono text-xs font-bold" :class="Number(i.remaining) > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'" dir="ltr">{{ fmt(i.remaining) }}</td>
                        <td class="px-3 py-2 text-right text-xs text-gray-600 dark:text-gray-400 hide-mobile">{{ i.employee || '—' }}</td>
                    </tr>
                    <tr v-if="!rows.length && !loading">
                        <td colspan="8" class="px-3 py-8 text-center text-gray-400 text-sm">لا يوجد مسافرون اليوم</td>
                    </tr>
                    <tr v-if="!rows.length && loading">
                        <td colspan="8" class="px-3 py-8 text-center text-gray-400 text-sm">جاري التحميل...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- تذييل: آخر تحديث -->
        <div class="mt-3 text-left">
            <span class="text-[10px] text-gray-400" dir="ltr">🕒 آخر تحديث: {{ updatedAt || '—' }} · تحديث تلقائي كل دقيقة</span>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const rows = ref([]);
const date = ref('');
const totalPax = ref(0);
const totalRemaining = ref(0);
const updatedAt = ref('');
const loading = ref(false);
let timer = null;

const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const fetchData = async () => {
    if (loading.value) return;
    loading.value = true;
    try {
        const { data } = await axios.get('/api/travelers-today', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        rows.value = data.rows || [];
        date.value = data.date || '';
        totalPax.value = data.totalPax || 0;
        totalRemaining.value = data.totalRemaining || 0;
        updatedAt.value = data.updated_at || '';
    } catch (e) {
        // تجاهل صامت — يُعاد المحاولة في الدورة التالية
    } finally {
        loading.value = false;
    }
};

// إعادة الجلب عند العودة لتبويب الصفحة
const onVisible = () => { if (document.visibilityState === 'visible') fetchData(); };

onMounted(() => {
    fetchData();
    timer = setInterval(fetchData, 60000); // كل دقيقة
    document.addEventListener('visibilitychange', onVisible);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisible);
});
</script>
