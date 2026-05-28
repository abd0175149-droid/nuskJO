<template>
    <AppLayout>
        <template #header>السلف</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3 flex-wrap">
                    <select v-model="filters.employee_id" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الموظفين</option>
                        <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }} ({{ emp.employee_number }})</option>
                    </select>
                    <select v-model="filters.status" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الحالات</option>
                        <option value="pending">معلقة</option>
                        <option value="approved">معتمدة</option>
                        <option value="rejected">مرفوضة</option>
                    </select>
                </div>
                <button v-if="can('advances.create')" @click="openModal()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ طلب سلفة</button>
            </div>

            <!-- Table -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <th class="px-4 py-3 text-right font-bold">الرقم</th>
                    <th class="px-4 py-3 text-right font-bold">الموظف</th>
                    <th class="px-4 py-3 text-right font-bold">المبلغ</th>
                    <th class="px-4 py-3 text-right font-bold">الأقساط</th>
                    <th class="px-4 py-3 text-right font-bold">قيمة القسط</th>
                    <th class="px-4 py-3 text-right font-bold">المتبقي</th>
                    <th class="px-4 py-3 text-right font-bold">طريقة الدفع</th>
                    <th class="px-4 py-3 text-right font-bold">الحالة</th>
                    <th class="px-4 py-3 text-center font-bold">إجراءات</th>
                </tr></thead>
                <tbody>
                    <tr v-for="adv in advances.data" :key="adv.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td class="px-4 py-3 text-right font-mono text-xs text-gold-700">{{ adv.advance_number }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="font-medium text-gray-900 dark:text-white text-xs">{{ adv.employee?.user?.name }}</div>
                            <div class="text-[10px] text-gray-400">{{ adv.employee?.employee_number }}</div>
                        </td>
                        <td class="px-4 py-3 text-right font-bold font-mono text-xs" dir="ltr">{{ Number(adv.amount).toLocaleString('en', {minimumFractionDigits:2}) }} {{ adv.currency }}</td>
                        <td class="px-4 py-3 text-right text-xs">{{ adv.installments_count }} قسط</td>
                        <td class="px-4 py-3 text-right font-mono text-xs" dir="ltr">{{ Number(adv.installment_amount).toLocaleString('en', {minimumFractionDigits:2}) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-xs" dir="ltr">
                            <span :class="Number(adv.remaining_amount) > 0 ? 'text-red-600' : 'text-green-600'">{{ Number(adv.remaining_amount).toLocaleString('en', {minimumFractionDigits:2}) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right text-xs">{{ adv.payment_method === 'cash' ? 'نقداً' : 'بنكي' }}</td>
                        <td class="px-4 py-3 text-right">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(adv.status)">{{ statusLabel(adv.status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <button v-if="adv.status==='pending' && can('advances.approve')" @click="approveAdv(adv)" class="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded-lg">✅</button>
                            <button v-if="adv.status==='pending' && can('advances.approve')" @click="rejectAdv(adv)" class="px-2 py-1 text-xs text-orange-600 hover:bg-orange-50 rounded-lg">❌</button>
                            <button v-if="adv.status==='pending' && can('advances.delete')" @click="deleteTarget=adv" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg">🗑️</button>
                        </td>
                    </tr>
                    <tr v-if="!advances.data?.length"><td colspan="9" class="px-5 py-12 text-center text-gray-400">لا يوجد سلف</td></tr>
                </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">طلب سلفة جديدة</h3><button @click="showForm=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div v-if="Object.keys($page.props.errors || {}).length" class="p-3 rounded-lg bg-red-50 text-red-700 text-sm">
                        <div v-for="(msg, key) in $page.props.errors" :key="key">{{ msg }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الموظف *</label>
                        <select v-model="form.employee_id" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="">اختر الموظف</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }} — {{ emp.currency }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">المبلغ *</label><input v-model="form.amount" type="number" step="0.01" min="1" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">عدد الأقساط *</label><input v-model="form.installments_count" type="number" min="1" max="24" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">طريقة الدفع *</label>
                        <select v-model="form.payment_method" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="cash">نقداً</option>
                            <option value="bank">تحويل بنكي</option>
                        </select>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السبب</label><textarea v-model="form.reason" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm resize-none"></textarea></div>

                    <!-- Preview -->
                    <div v-if="form.amount && form.installments_count" class="p-3 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-sm">
                        💡 قيمة القسط الشهري: <strong>{{ (form.amount / form.installments_count).toFixed(2) }}</strong> — لمدة {{ form.installments_count }} شهر
                    </div>

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
                <p class="text-sm text-gray-500 mb-6">حذف السلفة <strong>{{ deleteTarget.advance_number }}</strong>؟</p>
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

const props = defineProps({ advances: Object, employees: Array, filters: Object });
const filters = ref({ employee_id: props.filters?.employee_id || '', status: props.filters?.status || '' });
const showForm = ref(false);
const deleteTarget = ref(null);

const form = useForm({ employee_id: '', amount: '', installments_count: 3, reason: '', payment_method: 'cash' });

const openModal = () => { form.reset(); form.installments_count = 3; form.payment_method = 'cash'; form.clearErrors(); showForm.value = true; };
const submit = () => { form.post('/advances', { onSuccess: () => { showForm.value = false; form.reset(); }, preserveScroll: true, preserveState: false }); };

const approveAdv = (adv) => { if (confirm('اعتماد السلفة وتحويل المبلغ للموظف؟')) router.post('/advances/' + adv.id + '/approve', {}, { preserveScroll: true }); };
const rejectAdv = (adv) => { const r = prompt('سبب الرفض:'); if (r !== null) router.post('/advances/' + adv.id + '/reject', { rejection_reason: r }, { preserveScroll: true }); };
const confirmDel = () => { router.delete('/advances/' + deleteTarget.value.id, { preserveScroll: true, onSuccess: () => { deleteTarget.value = null; } }); };

const applyFilter = () => { router.get('/advances', { ...filters.value }, { preserveState: true, replace: true }); };

const statusClass = (s) => ({ pending: 'bg-yellow-100 text-yellow-700', approved: 'bg-green-100 text-green-700', rejected: 'bg-red-100 text-red-700' }[s] || '');
const statusLabel = (s) => ({ pending: 'معلقة', approved: 'معتمدة', rejected: 'مرفوضة' }[s] || s);
</script>
