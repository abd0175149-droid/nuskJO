<template>
    <AppLayout>
        <template #header>طلباتي</template>
        <div class="space-y-8">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            
            <!-- Leaves -->
            <div>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200">🏖️ طلبات الإجازة</h2>
                    <button @click="showLeaveForm=true" class="px-4 py-2 bg-gradient-to-r from-gold-500 to-gold-400 text-black text-sm font-bold rounded-xl shadow-md hover:shadow-lg transition-all">+ طلب إجازة</button>
                </div>
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <th class="px-4 py-3 text-right font-bold">النوع</th>
                        <th class="px-4 py-3 text-right font-bold">من</th>
                        <th class="px-4 py-3 text-right font-bold">إلى</th>
                        <th class="px-4 py-3 text-right font-bold">الأيام</th>
                        <th class="px-4 py-3 text-right font-bold">الحالة</th>
                        <th class="px-4 py-3 text-right font-bold">التاريخ</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="l in leaves" :key="l.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50">
                            <td class="px-4 py-3 text-right text-xs">{{ l.leave_type?.name || '—' }}</td>
                            <td class="px-4 py-3 text-right text-xs" dir="ltr">{{ l.start_date }}</td>
                            <td class="px-4 py-3 text-right text-xs" dir="ltr">{{ l.end_date }}</td>
                            <td class="px-4 py-3 text-right text-xs">{{ l.days_count }} يوم</td>
                            <td class="px-4 py-3 text-right"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(l.status)">{{ statusLabel(l.status) }}</span></td>
                            <td class="px-4 py-3 text-right text-xs text-gray-400" dir="ltr">{{ l.created_at?.split('T')[0] }}</td>
                        </tr>
                        <tr v-if="!leaves.length"><td colspan="6" class="px-5 py-8 text-center text-gray-400">لا يوجد طلبات إجازة</td></tr>
                    </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <!-- Advances -->
            <div>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200">💳 طلبات السلف</h2>
                    <button @click="showAdvanceForm=true" class="px-4 py-2 bg-gradient-to-r from-gold-500 to-gold-400 text-black text-sm font-bold rounded-xl shadow-md hover:shadow-lg transition-all">+ طلب سلفة</button>
                </div>
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                    <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <th class="px-4 py-3 text-right font-bold">الرقم</th>
                        <th class="px-4 py-3 text-right font-bold">المبلغ</th>
                        <th class="px-4 py-3 text-right font-bold">الأقساط</th>
                        <th class="px-4 py-3 text-right font-bold">المتبقي</th>
                        <th class="px-4 py-3 text-right font-bold">الحالة</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="a in advances" :key="a.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50">
                            <td class="px-4 py-3 text-right font-mono text-xs text-gold-700">{{ a.advance_number }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs" dir="ltr">{{ Number(a.amount).toLocaleString('en',{minimumFractionDigits:2}) }}</td>
                            <td class="px-4 py-3 text-right text-xs">{{ a.installments_count }} قسط</td>
                            <td class="px-4 py-3 text-right font-mono text-xs" dir="ltr" :class="Number(a.remaining_amount)>0?'text-red-600':'text-green-600'">{{ Number(a.remaining_amount).toLocaleString('en',{minimumFractionDigits:2}) }}</td>
                            <td class="px-4 py-3 text-right"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(a.status)">{{ statusLabel(a.status) }}</span></td>
                        </tr>
                        <tr v-if="!advances.length"><td colspan="5" class="px-5 py-8 text-center text-gray-400">لا يوجد طلبات سلف</td></tr>
                    </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Leave Modal -->
        <div v-if="showLeaveForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showLeaveForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
                <h3 class="text-lg font-bold mb-4">🏖️ تقديم طلب إجازة</h3>
                <form @submit.prevent="submitLeave" class="space-y-4">
                    <div>
                        <label class="block text-sm mb-1">نوع الإجازة *</label>
                        <select v-model="leaveForm.leave_type_id" required class="w-full px-4 py-2 rounded-xl border text-sm bg-gray-50 dark:bg-gray-800">
                            <option value="" disabled>اختر النوع</option>
                            <option v-for="t in leaveTypes" :key="t.id" :value="t.id">{{ t.name }} ({{ t.is_paid ? 'مدفوعة' : 'غير مدفوعة' }})</option>
                        </select>
                        <div v-if="leaveForm.errors.leave_type_id" class="text-red-500 text-xs mt-1">{{ leaveForm.errors.leave_type_id }}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm mb-1">من تاريخ *</label>
                            <input v-model="leaveForm.start_date" type="date" required class="w-full px-4 py-2 rounded-xl border text-sm bg-gray-50 dark:bg-gray-800" />
                            <div v-if="leaveForm.errors.start_date" class="text-red-500 text-xs mt-1">{{ leaveForm.errors.start_date }}</div>
                        </div>
                        <div>
                            <label class="block text-sm mb-1">إلى تاريخ *</label>
                            <input v-model="leaveForm.end_date" type="date" required class="w-full px-4 py-2 rounded-xl border text-sm bg-gray-50 dark:bg-gray-800" />
                            <div v-if="leaveForm.errors.end_date" class="text-red-500 text-xs mt-1">{{ leaveForm.errors.end_date }}</div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm mb-1">السبب / ملاحظات</label>
                        <textarea v-model="leaveForm.reason" rows="2" class="w-full px-4 py-2 rounded-xl border text-sm bg-gray-50 dark:bg-gray-800 resize-none"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 pt-4 border-t dark:border-gray-800">
                        <button type="button" @click="showLeaveForm=false" class="px-4 py-2 rounded-xl text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
                        <button type="submit" :disabled="leaveForm.processing" class="px-6 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">إرسال الطلب</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Create Advance Modal -->
        <div v-if="showAdvanceForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showAdvanceForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
                <h3 class="text-lg font-bold mb-4">💳 تقديم طلب سلفة</h3>
                <form @submit.prevent="submitAdvance" class="space-y-4">
                    <div>
                        <label class="block text-sm mb-1">المبلغ المطلوب *</label>
                        <input v-model="advanceForm.amount" type="number" step="0.01" min="1" required class="w-full px-4 py-2 rounded-xl border text-sm bg-gray-50 dark:bg-gray-800" dir="ltr" />
                        <div v-if="advanceForm.errors.amount" class="text-red-500 text-xs mt-1">{{ advanceForm.errors.amount }}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm mb-1">عدد الأقساط *</label>
                            <input v-model="advanceForm.installments_count" type="number" min="1" max="24" required class="w-full px-4 py-2 rounded-xl border text-sm bg-gray-50 dark:bg-gray-800" dir="ltr" />
                        </div>
                        <div>
                            <label class="block text-sm mb-1">شهر بدء الخصم *</label>
                            <input v-model="advanceForm.deduction_start_month" type="month" required class="w-full px-4 py-2 rounded-xl border text-sm bg-gray-50 dark:bg-gray-800" dir="ltr" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm mb-1">سبب طلب السلفة *</label>
                        <textarea v-model="advanceForm.reason" rows="2" required class="w-full px-4 py-2 rounded-xl border text-sm bg-gray-50 dark:bg-gray-800 resize-none"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 pt-4 border-t dark:border-gray-800">
                        <button type="button" @click="showAdvanceForm=false" class="px-4 py-2 rounded-xl text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
                        <button type="submit" :disabled="advanceForm.processing" class="px-6 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">إرسال الطلب</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';

const props = defineProps({ leaves: Array, advances: Array, balances: Array, leaveTypes: Array, employee: Object });

const statusClass = (s) => ({ pending:'bg-yellow-100 text-yellow-700', approved:'bg-green-100 text-green-700', rejected:'bg-red-100 text-red-700' }[s] || '');
const statusLabel = (s) => ({ pending:'معلقة', approved:'معتمدة', rejected:'مرفوضة' }[s] || s);

// Forms state
const showLeaveForm = ref(false);
const showAdvanceForm = ref(false);

const leaveForm = useForm({
    employee_id: props.employee?.id,
    leave_type_id: '',
    start_date: '',
    end_date: '',
    reason: ''
});

const advanceForm = useForm({
    employee_id: props.employee?.id,
    amount: '',
    installments_count: 1,
    deduction_start_month: '',
    reason: ''
});

const submitLeave = () => {
    leaveForm.post('/leaves', {
        onSuccess: () => { showLeaveForm.value = false; leaveForm.reset(); },
        preserveScroll: true
    });
};

const submitAdvance = () => {
    advanceForm.post('/advances', {
        onSuccess: () => { showAdvanceForm.value = false; advanceForm.reset(); },
        preserveScroll: true
    });
};
</script>
