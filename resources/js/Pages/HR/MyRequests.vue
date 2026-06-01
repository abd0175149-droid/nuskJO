<template>
    <AppLayout>
        <template #header>طلباتي</template>
        <div class="space-y-8">
            <!-- Leaves -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200">🏖️ طلبات الإجازة</h2>
                    <button @click="showLeaveModal = true" class="px-4 py-2 bg-gold-600 hover:bg-gold-700 text-white rounded-lg text-sm font-bold shadow-md shadow-gold-500/20 transition-all">
                        + طلب إجازة
                    </button>
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
                        <tr v-for="l in leaves" :key="l.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/50">
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
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200">💳 طلبات السلف</h2>
                    <button @click="showAdvanceModal = true" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-bold shadow-md transition-all">
                        + طلب سلفة
                    </button>
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
                        <tr v-for="a in advances" :key="a.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="px-4 py-3 text-right font-mono text-xs text-gold-700 dark:text-gold-400">{{ a.advance_number }}</td>
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

        <!-- Leave Modal -->
        <div v-if="showLeaveModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
                <div class="p-4 border-b dark:border-gray-800 flex justify-between items-center">
                    <h3 class="font-bold text-lg dark:text-white">طلب إجازة جديد</h3>
                    <button @click="showLeaveModal = false" class="text-gray-400 hover:text-red-500">✖</button>
                </div>
                <form @submit.prevent="submitLeave" class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-bold mb-1 dark:text-gray-300">نوع الإجازة</label>
                        <select v-model="leaveForm.leave_type_id" class="w-full border rounded-lg p-2 dark:bg-gray-800 dark:border-gray-700 dark:text-white" required>
                            <option value="">اختر نوع الإجازة...</option>
                            <option v-for="t in leaveTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                        <div v-if="leaveForm.errors.leave_type_id" class="text-red-500 text-xs mt-1">{{ leaveForm.errors.leave_type_id }}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold mb-1 dark:text-gray-300">من تاريخ</label>
                            <input type="date" v-model="leaveForm.start_date" class="w-full border rounded-lg p-2 dark:bg-gray-800 dark:border-gray-700 dark:text-white" required>
                            <div v-if="leaveForm.errors.start_date" class="text-red-500 text-xs mt-1">{{ leaveForm.errors.start_date }}</div>
                        </div>
                        <div>
                            <label class="block text-sm font-bold mb-1 dark:text-gray-300">إلى تاريخ</label>
                            <input type="date" v-model="leaveForm.end_date" class="w-full border rounded-lg p-2 dark:bg-gray-800 dark:border-gray-700 dark:text-white" required>
                            <div v-if="leaveForm.errors.end_date" class="text-red-500 text-xs mt-1">{{ leaveForm.errors.end_date }}</div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1 dark:text-gray-300">السبب (اختياري)</label>
                        <textarea v-model="leaveForm.reason" rows="2" class="w-full border rounded-lg p-2 dark:bg-gray-800 dark:border-gray-700 dark:text-white"></textarea>
                    </div>
                    <div class="pt-4 flex justify-end gap-2">
                        <button type="button" @click="showLeaveModal = false" class="px-4 py-2 border rounded-lg dark:border-gray-700 dark:text-gray-300">إلغاء</button>
                        <button type="submit" :disabled="leaveForm.processing" class="px-4 py-2 bg-gold-600 text-white rounded-lg disabled:opacity-50">إرسال الطلب</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Advance Modal -->
        <div v-if="showAdvanceModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
                <div class="p-4 border-b dark:border-gray-800 flex justify-between items-center">
                    <h3 class="font-bold text-lg dark:text-white">طلب سلفة جديد</h3>
                    <button @click="showAdvanceModal = false" class="text-gray-400 hover:text-red-500">✖</button>
                </div>
                <form @submit.prevent="submitAdvance" class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-bold mb-1 dark:text-gray-300">المبلغ</label>
                        <input type="number" step="0.01" min="1" v-model="advanceForm.amount" class="w-full border rounded-lg p-2 dark:bg-gray-800 dark:border-gray-700 dark:text-white" required>
                        <div v-if="advanceForm.errors.amount" class="text-red-500 text-xs mt-1">{{ advanceForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1 dark:text-gray-300">عدد الأقساط (الأشهر)</label>
                        <input type="number" min="1" max="24" v-model="advanceForm.installments_count" class="w-full border rounded-lg p-2 dark:bg-gray-800 dark:border-gray-700 dark:text-white" required>
                        <div v-if="advanceForm.errors.installments_count" class="text-red-500 text-xs mt-1">{{ advanceForm.errors.installments_count }}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1 dark:text-gray-300">السبب (اختياري)</label>
                        <textarea v-model="advanceForm.reason" rows="2" class="w-full border rounded-lg p-2 dark:bg-gray-800 dark:border-gray-700 dark:text-white"></textarea>
                    </div>
                    <div class="pt-4 flex justify-end gap-2">
                        <button type="button" @click="showAdvanceModal = false" class="px-4 py-2 border rounded-lg dark:border-gray-700 dark:text-gray-300">إلغاء</button>
                        <button type="submit" :disabled="advanceForm.processing" class="px-4 py-2 bg-blue-600 text-white rounded-lg disabled:opacity-50">إرسال الطلب</button>
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

const props = defineProps({ leaves: Array, advances: Array, leaveTypes: Array, employee: Object });

const showLeaveModal = ref(false);
const showAdvanceModal = ref(false);

const leaveForm = useForm({
    employee_id: props.employee.id,
    leave_type_id: '',
    start_date: '',
    end_date: '',
    reason: ''
});

const submitLeave = () => {
    leaveForm.post('/leaves', {
        preserveScroll: true,
        onSuccess: () => {
            showLeaveModal.value = false;
            leaveForm.reset();
        }
    });
};

const advanceForm = useForm({
    employee_id: props.employee.id,
    amount: '',
    installments_count: 1,
    start_month: new Date().getMonth() + 2 > 12 ? 1 : new Date().getMonth() + 2,
    start_year: new Date().getMonth() + 2 > 12 ? new Date().getFullYear() + 1 : new Date().getFullYear(),
    reason: ''
});

const submitAdvance = () => {
    advanceForm.post('/advances', {
        preserveScroll: true,
        onSuccess: () => {
            showAdvanceModal.value = false;
            advanceForm.reset();
        }
    });
};

const statusClass = (s) => ({ pending:'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-500', approved:'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-500', rejected:'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-500' }[s] || '');
const statusLabel = (s) => ({ pending:'معلقة', approved:'معتمدة', rejected:'مرفوضة' }[s] || s);
</script>
