<template>
    <AppLayout>
        <template #header>الفترات المحاسبية والإقفال</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <!-- السنوات المالية -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">📆 السنوات المالية</h3>
                    <button @click="showFiscalForm = true" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ سنة مالية جديدة</button>
                </div>

                <table class="w-full" v-if="fiscalYears?.length">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-bold">السنة</th>
                            <th class="px-4 py-3 text-right text-xs font-bold">من</th>
                            <th class="px-4 py-3 text-right text-xs font-bold">إلى</th>
                            <th class="px-4 py-3 text-right text-xs font-bold">الحالة</th>
                            <th class="px-4 py-3 text-right text-xs font-bold">ملاحظات</th>
                            <th class="px-4 py-3 text-center text-xs font-bold">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="fy in fiscalYears" :key="fy.id" class="border-b hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td class="px-4 py-3 text-sm font-bold text-gold-700">{{ fy.year }}</td>
                            <td class="px-4 py-3 text-sm font-mono">{{ fy.start_date?.split('T')[0] }}</td>
                            <td class="px-4 py-3 text-sm font-mono">{{ fy.end_date?.split('T')[0] }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span :class="fy.status === 'closed' ? 'text-red-600 bg-red-50 px-2 py-1 rounded' : 'text-green-600 bg-green-50 px-2 py-1 rounded'" class="text-xs font-bold">
                                    {{ fy.status === 'closed' ? '🔒 مقفلة' : '🔓 مفتوحة' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ fy.notes || '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <button v-if="fy.status === 'open'" @click="deleteFiscalYear(fy)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg">🗑️ حذف</button>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-center text-gray-400 py-6">لا توجد سنوات مالية مسجلة — أنشئ واحدة للبدء</p>
            </div>

            <!-- فورم إنشاء سنة مالية -->
            <div v-if="showFiscalForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showFiscalForm = false">
                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
                    <div class="flex items-center justify-between mb-5">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">إنشاء سنة مالية</h3>
                        <button @click="showFiscalForm = false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button>
                    </div>
                    <form @submit.prevent="submitFiscalYear" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">السنة *</label>
                            <input v-model="fyForm.year" type="number" min="2020" max="2099" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none" dir="ltr"/>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">تاريخ البداية *</label>
                                <input v-model="fyForm.start_date" type="date" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none" dir="ltr"/>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">تاريخ النهاية *</label>
                                <input v-model="fyForm.end_date" type="date" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none" dir="ltr"/>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label>
                            <textarea v-model="fyForm.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" :disabled="fyForm.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">✅ إنشاء</button>
                            <button type="button" @click="showFiscalForm = false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- إقفال السنة المالية -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-lg font-bold mb-4">📅 إقفال السنة المالية</h3>
                <p class="text-sm text-gray-500 mb-4">
                    يقوم بإقفال حسابات الإيرادات والمصروفات وترحيل صافي الربح/الخسارة إلى الأرباح المحتجزة.
                    هذه العملية لا يمكن التراجع عنها بسهولة.
                </p>
                <div class="flex items-center gap-4">
                    <select v-model="closeYearValue" class="px-4 py-2.5 rounded-xl border text-sm">
                        <option :value="null" disabled>اختر السنة</option>
                        <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
                        <option :value="currentYear">{{ currentYear }} (السنة الحالية)</option>
                    </select>
                    <button @click="doCloseYear" :disabled="!closeYearValue || closingYear"
                        class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-600 hover:bg-red-700 disabled:opacity-50">
                        {{ closingYear ? '⏳ جاري الإقفال...' : '🔒 إقفال السنة' }}
                    </button>
                </div>
            </div>

            <!-- إقفال/فتح شهر -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-lg font-bold mb-4">📆 إقفال/فتح فترة شهرية</h3>
                <div class="flex items-center gap-4">
                    <select v-model="periodYear" class="px-4 py-2.5 rounded-xl border text-sm w-28">
                        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <select v-model="periodMonth" class="px-4 py-2.5 rounded-xl border text-sm w-28">
                        <option :value="0">السنة كاملة</option>
                        <option v-for="m in 12" :key="m" :value="m">شهر {{ m }}</option>
                    </select>
                    <button @click="doClosePeriod" class="px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-orange-600 hover:bg-orange-700">🔒 إقفال</button>
                    <button @click="doOpenPeriod" class="px-5 py-2.5 rounded-xl text-sm font-bold text-gray-700 bg-gray-200 hover:bg-gray-300">🔓 فتح</button>
                </div>
            </div>

            <!-- جدول الفترات -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-right text-xs font-bold">السنة</th>
                            <th class="px-4 py-3 text-right text-xs font-bold">الشهر</th>
                            <th class="px-4 py-3 text-right text-xs font-bold">الحالة</th>
                            <th class="px-4 py-3 text-right text-xs font-bold">تاريخ الإقفال</th>
                            <th class="px-4 py-3 text-right text-xs font-bold">رقم القيد</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in periods" :key="`${p.year}-${p.month}`" class="border-b hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td class="px-4 py-3 text-sm font-bold">{{ p.year }}</td>
                            <td class="px-4 py-3 text-sm">{{ p.month === 0 ? 'السنة كاملة' : `شهر ${p.month}` }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span :class="p.status === 'closed' ? 'text-red-600 bg-red-50 px-2 py-1 rounded' : 'text-green-600 bg-green-50 px-2 py-1 rounded'" class="text-xs font-bold">
                                    {{ p.status === 'closed' ? '🔒 مقفل' : '🔓 مفتوح' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ p.closed_at ? new Date(p.closed_at).toLocaleString('ar') : '—' }}</td>
                            <td class="px-4 py-3 text-sm font-mono text-gold-700">{{ p.closing_entry_number || '—' }}</td>
                        </tr>
                        <tr v-if="!periods?.length">
                            <td colspan="5" class="px-4 py-8 text-center text-gray-400">لا توجد فترات مُسجلة</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ periods: Array, availableYears: Array, fiscalYears: Array });
const currentYear = new Date().getFullYear();
const yearOptions = Array.from({ length: 5 }, (_, i) => currentYear - i);

const closeYearValue = ref(null);
const closingYear = ref(false);
const periodYear = ref(currentYear);
const periodMonth = ref(0);
const showFiscalForm = ref(false);

const fyForm = useForm({
    year: currentYear,
    start_date: `${currentYear}-01-01`,
    end_date: `${currentYear}-12-31`,
    notes: '',
});

// تحديث التواريخ تلقائياً عند تغيير السنة
watch(() => fyForm.year, (y) => {
    if (y >= 2020 && y <= 2099) {
        fyForm.start_date = `${y}-01-01`;
        fyForm.end_date = `${y}-12-31`;
    }
});

const submitFiscalYear = () => {
    fyForm.post('/accounting/fiscal-years', {
        onSuccess: () => { showFiscalForm.value = false; fyForm.reset(); },
        preserveScroll: true,
    });
};

const deleteFiscalYear = (fy) => {
    if (!confirm(`حذف السنة المالية ${fy.year}؟`)) return;
    router.delete(`/accounting/fiscal-years/${fy.id}`, { preserveScroll: true });
};

const doCloseYear = () => {
    if (!confirm(`هل أنت متأكد من إقفال السنة المالية ${closeYearValue.value}؟\nسيتم إنشاء قيد إقفال وترحيل الأرصدة.`)) return;
    closingYear.value = true;
    router.post('/accounting/close-year', { year: closeYearValue.value }, {
        onFinish: () => closingYear.value = false,
    });
};

const doClosePeriod = () => {
    router.post('/accounting/periods/close', { year: periodYear.value, month: periodMonth.value });
};

const doOpenPeriod = () => {
    if (!confirm('هل تريد فتح هذه الفترة؟ سيُسمح بالتسجيل فيها.')) return;
    router.post('/accounting/periods/open', { year: periodYear.value, month: periodMonth.value });
};
</script>
