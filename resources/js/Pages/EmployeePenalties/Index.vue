<template>
    <AppLayout>
        <template #header>مخالفات الموظفين</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3 flex-wrap">
                    <select v-model="filters.employee_id" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الموظفين</option>
                        <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }} ({{ emp.employee_number }})</option>
                    </select>
                    <select v-model="filters.penalty_type" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الأنواع</option>
                        <option value="warning">لفت نظر</option>
                        <option value="deduction">خصم مالي</option>
                    </select>
                </div>
                <button v-if="can('penalties.create')" @click="openModal()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ إصدار مخالفة</button>
            </div>

            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <th class="px-4 py-3 text-right font-bold">الرقم</th>
                    <th class="px-4 py-3 text-right font-bold">الموظف</th>
                    <th class="px-4 py-3 text-right font-bold">النوع</th>
                    <th class="px-4 py-3 text-right font-bold">أيام الخصم</th>
                    <th class="px-4 py-3 text-right font-bold">مبلغ الخصم</th>
                    <th class="px-4 py-3 text-right font-bold">التاريخ</th>
                    <th class="px-4 py-3 text-right font-bold">السبب</th>
                    <th class="px-4 py-3 text-right font-bold">مخصومة؟</th>
                    <th class="px-4 py-3 text-right font-bold">بواسطة</th>
                    <th class="px-4 py-3 text-center font-bold">إجراءات</th>
                </tr></thead>
                <tbody>
                    <tr v-for="p in penalties.data" :key="p.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td class="px-4 py-3 text-right font-mono text-xs text-gold-700">{{ p.penalty_number }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="font-medium text-gray-900 dark:text-white text-xs">{{ p.employee?.user?.name }}</div>
                            <div class="text-[10px] text-gray-400">{{ p.employee?.employee_number }}</div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="p.penalty_type === 'warning' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'">{{ p.penalty_type === 'warning' ? 'لفت نظر' : 'خصم مالي' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right text-xs">{{ p.deduction_days || '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono text-xs text-red-600" dir="ltr">{{ p.penalty_type === 'deduction' ? Number(p.deduction_amount).toLocaleString('en', {minimumFractionDigits:2}) : '—' }}</td>
                        <td class="px-4 py-3 text-right text-xs" dir="ltr">{{ p.penalty_date }}</td>
                        <td class="px-4 py-3 text-right text-xs text-gray-600 dark:text-gray-400 max-w-[200px] truncate">{{ p.reason }}</td>
                        <td class="px-4 py-3 text-right">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="p.is_deducted ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">{{ p.is_deducted ? 'نعم' : 'لا' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right text-xs text-gray-500">{{ p.creator?.name || '—' }}</td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <button v-if="!p.is_deducted && can('penalties.delete')" @click="deleteTarget=p" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg">🗑️</button>
                        </td>
                    </tr>
                    <tr v-if="!penalties.data?.length"><td colspan="10" class="px-5 py-12 text-center text-gray-400">لا يوجد مخالفات</td></tr>
                </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">إصدار مخالفة</h3><button @click="showForm=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الموظف *</label>
                        <select v-model="form.employee_id" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="">اختر الموظف</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">نوع المخالفة *</label>
                            <select v-model="form.penalty_type" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                                <option value="warning">لفت نظر</option>
                                <option value="deduction">خصم مالي</option>
                            </select>
                        </div>
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">التاريخ *</label><input v-model="form.penalty_date" type="date" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                    </div>
                    <div v-if="form.penalty_type === 'deduction'" class="grid grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">أيام الخصم</label><input v-model="form.deduction_days" type="number" min="0" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">مبلغ الخصم</label><input v-model="form.deduction_amount" type="number" step="0.01" min="0" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السبب *</label><textarea v-model="form.reason" rows="3" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm resize-none"></textarea></div>
                    <div class="flex gap-3">
                        <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">✅ إصدار المخالفة</button>
                        <button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Modal -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-2">تأكيد الحذف</h3>
                <p class="text-sm text-gray-500 mb-6">حذف المخالفة <strong>{{ deleteTarget.penalty_number }}</strong>؟</p>
                <div class="flex gap-3 justify-center">
                    <button @click="confirmDel" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600">🗑️ حذف</button>
                    <button @click="deleteTarget=null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                </div>
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

const props = defineProps({ penalties: Object, employees: Array, filters: Object });
const filters = ref({ employee_id: props.filters?.employee_id || '', penalty_type: props.filters?.penalty_type || '' });
const showForm = ref(false);
const deleteTarget = ref(null);

const today = new Date().toISOString().split('T')[0];
const form = useForm({ employee_id: '', penalty_type: 'warning', deduction_days: 0, deduction_amount: 0, penalty_date: today, reason: '' });

const openModal = () => { form.reset(); form.penalty_type = 'warning'; form.penalty_date = today; form.clearErrors(); showForm.value = true; };
const submit = () => { form.post('/penalties', { onSuccess: () => { showForm.value = false; form.reset(); }, preserveScroll: true, preserveState: false }); };
const confirmDel = () => { router.delete('/penalties/' + deleteTarget.value.id, { preserveScroll: true, onSuccess: () => { deleteTarget.value = null; } }); };
const applyFilter = () => { router.get('/penalties', { ...filters.value }, { preserveState: true, replace: true }); };
</script>
