<template>
    <AppLayout>
        <template #header>مسيرات الرواتب</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3 flex-wrap">
                    <select v-model="filters.month" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الأشهر</option>
                        <option v-for="m in 12" :key="m" :value="m">{{ monthNames[m] }}</option>
                    </select>
                    <select v-model="filters.year" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل السنوات</option>
                        <option v-for="y in [2025, 2026, 2027]" :key="y" :value="y">{{ y }}</option>
                    </select>
                    <select v-model="filters.currency" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل العملات</option>
                        <option value="SAR">ريال سعودي</option>
                        <option value="JOD">دينار أردني</option>
                    </select>
                    <select v-model="filters.status" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الحالات</option>
                        <option value="draft">مسودة</option>
                        <option value="pending">معلقة</option>
                        <option value="approved">معتمدة</option>
                        <option value="rejected">مرفوضة</option>
                    </select>
                </div>
                <button v-if="can('payroll.generate')" @click="showGenerate=true" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ توليد مسير جديد</button>
            </div>

            <!-- Table -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <th class="px-4 py-3 text-right font-bold">الرقم</th>
                    <th class="px-4 py-3 text-right font-bold">الفترة</th>
                    <th class="px-4 py-3 text-right font-bold">العملة</th>
                    <th class="px-4 py-3 text-right font-bold">الموظفين</th>
                    <th class="px-4 py-3 text-right font-bold">إجمالي الاستحقاقات</th>
                    <th class="px-4 py-3 text-right font-bold">إجمالي الخصومات</th>
                    <th class="px-4 py-3 text-right font-bold">صافي الرواتب</th>
                    <th class="px-4 py-3 text-right font-bold">الحالة</th>
                    <th class="px-4 py-3 text-center font-bold">إجراءات</th>
                </tr></thead>
                <tbody>
                    <tr v-for="p in payrolls.data" :key="p.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td class="px-4 py-3 text-right font-mono text-xs text-gold-700">{{ p.payroll_number }}</td>
                        <td class="px-4 py-3 text-right text-xs font-medium">{{ monthNames[p.month] }} {{ p.year }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ p.currency }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ p.employees_count }} موظف</td>
                        <td class="px-4 py-3 text-right font-mono text-xs text-green-600" dir="ltr">{{ fmt(p.total_basic) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-xs text-red-600" dir="ltr">{{ fmt(p.total_deductions) }}</td>
                        <td class="px-4 py-3 text-right font-bold font-mono text-xs" dir="ltr">{{ fmt(p.total_net) }}</td>
                        <td class="px-4 py-3 text-right"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(p.status)">{{ statusLabel(p.status) }}</span></td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <a :href="'/payrolls/' + p.id" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg">👁️</a>
                            <button v-if="p.status==='draft' && can('payroll.generate')" @click="submitPayroll(p)" class="px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 rounded-lg" title="تقديم للاعتماد">📤</button>
                            <button v-if="p.status==='pending' && can('payroll.approve')" @click="approvePayroll(p)" class="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded-lg">✅</button>
                            <button v-if="p.status==='pending' && can('payroll.reject')" @click="rejectPayroll(p)" class="px-2 py-1 text-xs text-orange-600 hover:bg-orange-50 rounded-lg">❌</button>
                            <a v-if="p.status==='approved'" :href="'/payrolls/' + p.id + '/print'" target="_blank" class="px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 rounded-lg">🖨️</a>
                            <a v-if="p.status==='approved'" :href="'/payrolls/' + p.id + '/export-bank'" class="px-2 py-1 text-xs text-teal-600 hover:bg-teal-50 rounded-lg" title="تصدير بنكي">🏦</a>
                        </td>
                    </tr>
                    <tr v-if="!payrolls.data?.length"><td colspan="9" class="px-5 py-12 text-center text-gray-400">لا يوجد مسيرات رواتب</td></tr>
                </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Generate Modal -->
        <div v-if="showGenerate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showGenerate=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">توليد مسير رواتب جديد</h3><button @click="showGenerate=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="generatePayroll" class="space-y-4">
                    <div v-if="Object.keys($page.props.errors || {}).length" class="p-3 rounded-lg bg-red-50 text-red-700 text-sm">
                        <div v-for="(msg, key) in $page.props.errors" :key="key">{{ msg }}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الشهر *</label>
                            <select v-model="genForm.month" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                                <option v-for="m in 12" :key="m" :value="m">{{ monthNames[m] }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السنة *</label>
                            <select v-model="genForm.year" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                                <option v-for="y in [2025, 2026, 2027]" :key="y" :value="y">{{ y }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">العملة *</label>
                        <select v-model="genForm.currency" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="SAR">ريال سعودي (SAR)</option>
                            <option value="JOD">دينار أردني (JOD)</option>
                        </select>
                    </div>
                    <div class="flex gap-3">
                        <button type="submit" :disabled="genForm.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">⚙️ توليد المسير</button>
                        <button type="button" @click="showGenerate=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
const { can } = usePermissions();

const props = defineProps({ payrolls: Object, filters: Object });
const filters = ref({ month: props.filters?.month || '', year: props.filters?.year || '', currency: props.filters?.currency || '', status: props.filters?.status || '' });
const showGenerate = ref(false);

const now = new Date();
const genForm = useForm({ month: now.getMonth() + 1, year: now.getFullYear(), currency: 'SAR' });

const monthNames = { 1:'يناير', 2:'فبراير', 3:'مارس', 4:'أبريل', 5:'مايو', 6:'يونيو', 7:'يوليو', 8:'أغسطس', 9:'سبتمبر', 10:'أكتوبر', 11:'نوفمبر', 12:'ديسمبر' };
const fmt = (v) => Number(v).toLocaleString('en', { minimumFractionDigits: 2 });

const generatePayroll = () => { genForm.post('/payrolls/generate', { onSuccess: () => { showGenerate.value = false; }, preserveState: false }); };
const submitPayroll = (p) => { if (confirm('تقديم المسير للاعتماد؟')) router.post('/payrolls/' + p.id + '/submit', {}, { preserveScroll: true }); };
const approvePayroll = (p) => { if (confirm('اعتماد المسير وتحويل الرواتب؟')) router.post('/payrolls/' + p.id + '/approve', {}, { preserveScroll: true }); };
const rejectPayroll = (p) => { const r = prompt('سبب الرفض:'); if (r !== null) router.post('/payrolls/' + p.id + '/reject', { rejection_reason: r }, { preserveScroll: true }); };
const applyFilter = () => { router.get('/payrolls', { ...filters.value }, { preserveState: true, replace: true }); };

const statusClass = (s) => ({ draft: 'bg-gray-100 text-gray-600', pending: 'bg-yellow-100 text-yellow-700', approved: 'bg-green-100 text-green-700', rejected: 'bg-red-100 text-red-700' }[s] || '');
const statusLabel = (s) => ({ draft: 'مسودة', pending: 'معلقة', approved: 'معتمدة', rejected: 'مرفوضة' }[s] || s);
</script>
