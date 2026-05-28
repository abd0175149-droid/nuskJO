<template>
    <AppLayout>
        <template #header>الإجازات</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3 flex-wrap">
                    <select v-model="filters.employee_id" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الموظفين</option>
                        <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }} ({{ emp.employee_number }})</option>
                    </select>
                    <select v-model="filters.leave_type_id" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الأنواع</option>
                        <option v-for="lt in leaveTypes" :key="lt.id" :value="lt.id">{{ lt.name }}</option>
                    </select>
                    <select v-model="filters.status" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الحالات</option>
                        <option value="pending">معلقة</option>
                        <option value="approved">معتمدة</option>
                        <option value="rejected">مرفوضة</option>
                    </select>
                </div>
                <button v-if="can('leaves.create')" @click="openModal()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ تقديم طلب إجازة</button>
            </div>

            <!-- Table -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <th class="px-4 py-3 text-right font-bold">الرقم</th>
                    <th class="px-4 py-3 text-right font-bold">الموظف</th>
                    <th class="px-4 py-3 text-right font-bold">نوع الإجازة</th>
                    <th class="px-4 py-3 text-right font-bold">من</th>
                    <th class="px-4 py-3 text-right font-bold">إلى</th>
                    <th class="px-4 py-3 text-right font-bold">الأيام</th>
                    <th class="px-4 py-3 text-right font-bold">مدفوعة</th>
                    <th class="px-4 py-3 text-right font-bold">الحالة</th>
                    <th class="px-4 py-3 text-center font-bold">إجراءات</th>
                </tr></thead>
                <tbody>
                    <tr v-for="lr in leaveRequests.data" :key="lr.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td class="px-4 py-3 text-right font-mono text-xs text-gold-700">{{ lr.request_number }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="font-medium text-gray-900 dark:text-white text-xs">{{ lr.employee?.user?.name }}</div>
                            <div class="text-[10px] text-gray-400">{{ lr.employee?.employee_number }}</div>
                        </td>
                        <td class="px-4 py-3 text-right text-xs">{{ lr.leave_type?.name }}</td>
                        <td class="px-4 py-3 text-right text-xs" dir="ltr">{{ lr.start_date }}</td>
                        <td class="px-4 py-3 text-right text-xs" dir="ltr">{{ lr.end_date }}</td>
                        <td class="px-4 py-3 text-right font-bold text-xs">{{ lr.days_count }} يوم</td>
                        <td class="px-4 py-3 text-right">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="lr.is_paid ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">{{ lr.is_paid ? 'مدفوعة' : 'بدون راتب' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(lr.status)">{{ statusLabel(lr.status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <button v-if="lr.status==='pending' && can('leaves.approve')" @click="approveLeave(lr)" class="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded-lg" title="اعتماد">✅</button>
                            <button v-if="lr.status==='pending' && can('leaves.approve')" @click="rejectLeave(lr)" class="px-2 py-1 text-xs text-orange-600 hover:bg-orange-50 rounded-lg" title="رفض">❌</button>
                            <button v-if="lr.status==='pending' && can('leaves.delete')" @click="deleteTarget=lr" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg" title="حذف">🗑️</button>
                        </td>
                    </tr>
                    <tr v-if="!leaveRequests.data?.length"><td colspan="9" class="px-5 py-12 text-center text-gray-400">لا يوجد طلبات إجازة</td></tr>
                </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">تقديم طلب إجازة</h3><button @click="showForm=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div v-if="Object.keys($page.props.errors || {}).length" class="p-3 rounded-lg bg-red-50 text-red-700 text-sm">
                        <div v-for="(msg, key) in $page.props.errors" :key="key">{{ msg }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الموظف *</label>
                        <select v-model="form.employee_id" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="">اختر الموظف</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }} ({{ emp.employee_number }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">نوع الإجازة *</label>
                        <select v-model="form.leave_type_id" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="">اختر النوع</option>
                            <option v-for="lt in leaveTypes" :key="lt.id" :value="lt.id">{{ lt.name }} ({{ lt.country }}) — {{ lt.default_days }} يوم</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">من تاريخ *</label><input v-model="form.start_date" type="date" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">إلى تاريخ *</label><input v-model="form.end_date" type="date" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السبب</label><textarea v-model="form.reason" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm resize-none"></textarea></div>
                    <div class="flex gap-3">
                        <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">✅ تقديم الطلب</button>
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
                <p class="text-sm text-gray-500 mb-6">حذف طلب الإجازة <strong>{{ deleteTarget.request_number }}</strong>؟</p>
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

const props = defineProps({ leaveRequests: Object, employees: Array, leaveTypes: Array, filters: Object });
const filters = ref({ employee_id: props.filters?.employee_id || '', leave_type_id: props.filters?.leave_type_id || '', status: props.filters?.status || '' });
const showForm = ref(false);
const deleteTarget = ref(null);

const today = new Date().toISOString().split('T')[0];
const form = useForm({ employee_id: '', leave_type_id: '', start_date: today, end_date: today, reason: '' });

const openModal = () => { form.reset(); form.start_date = today; form.end_date = today; form.clearErrors(); showForm.value = true; };
const submit = () => { form.post('/leaves', { onSuccess: () => { showForm.value = false; form.reset(); }, preserveScroll: true, preserveState: false }); };

const approveLeave = (lr) => { if (confirm('اعتماد طلب الإجازة؟')) router.post('/leaves/' + lr.id + '/approve', {}, { preserveScroll: true }); };
const rejectLeave = (lr) => { const r = prompt('سبب الرفض:'); if (r !== null) router.post('/leaves/' + lr.id + '/reject', { rejection_reason: r }, { preserveScroll: true }); };
const confirmDel = () => { router.delete('/leaves/' + deleteTarget.value.id, { preserveScroll: true, onSuccess: () => { deleteTarget.value = null; } }); };

const applyFilter = () => { router.get('/leaves', { ...filters.value }, { preserveState: true, replace: true }); };

const statusClass = (s) => ({ pending: 'bg-yellow-100 text-yellow-700', approved: 'bg-green-100 text-green-700', rejected: 'bg-red-100 text-red-700' }[s] || '');
const statusLabel = (s) => ({ pending: 'معلقة', approved: 'معتمدة', rejected: 'مرفوضة' }[s] || s);
</script>
