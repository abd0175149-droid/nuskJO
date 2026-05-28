<template>
    <AppLayout>
        <template #header>الحوالات</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <div class="flex items-center gap-3 flex-wrap">
                    <input v-model="search" type="text" placeholder="بحث بالرقم أو الوكيل..." class="w-64 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                    <select v-model="statusFilter" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الحالات</option>
                        <option value="pending">معلقة</option>
                        <option value="approved">معتمدة</option>
                        <option value="rejected">مرفوضة</option>
                        <option value="editing">تحت التعديل</option>
                    </select>
                </div>
                <button v-if="can('transfers.create')" @click="openForm()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md w-full sm:w-auto">+ حوالة جديدة</button>
            </div>

            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm responsive-table">
                    <thead><tr class="bg-gray-50 text-gray-600">
                        <th class="px-5 py-3 text-right font-bold">الرقم</th>
                        <th class="px-5 py-3 text-right font-bold">الوكيل</th>
                        <th class="px-5 py-3 text-right font-bold">المبلغ (SAR)</th>
                        <th class="px-5 py-3 text-right font-bold">التكلفة (JOD)</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">الفرق (JOD)</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">طريقة الدفع</th>
                        <th class="px-5 py-3 text-right font-bold">الحالة</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">بواسطة</th>
                        <th class="px-5 py-3 text-center font-bold">إجراءات</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="t in transfers.data" :key="t.id" :data-row-id="t.id"
                            class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30"
                            :class="{ 'row-glow': isHighlighted(t.id) }">
                            <td data-label="الرقم" class="px-5 py-3 font-mono text-xs text-gold-700">{{ t.transfer_number }}</td>
                            <td data-label="الوكيل" class="px-5 py-3 font-medium">{{ t.agent?.name }}</td>
                            <td data-label="المبلغ SAR" class="px-5 py-3 font-bold font-mono text-green-600">{{ Number(t.amount_sar).toLocaleString('en',{minimumFractionDigits:2}) }}</td>
                            <td data-label="التكلفة JOD" class="px-5 py-3 font-mono">{{ t.cost_jod ? Number(t.cost_jod).toLocaleString('en',{minimumFractionDigits:3}) : '—' }}</td>
                            <td data-label="الفرق" class="px-5 py-3 font-mono text-xs hide-mobile" :class="diffClass(t)">
                                {{ t.difference_amount != 0 ? Number(Math.abs(t.difference_amount)).toLocaleString('en',{minimumFractionDigits:3}) : '—' }}
                                <span v-if="t.difference_type==='expense'" class="text-red-500"> (مصروف)</span>
                                <span v-if="t.difference_type==='revenue'" class="text-green-500"> (إيراد)</span>
                            </td>
                            <td data-label="الدفع" class="px-5 py-3 hide-mobile">{{ payMethods[t.payment_method] }}</td>
                            <td data-label="الحالة" class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="statusClasses[t.status]">{{ statusLabels[t.status] }}</span></td>
                            <td data-label="بواسطة" class="px-5 py-3 text-xs text-gray-500 hide-mobile"><div>📝 {{ t.creator?.name || '—' }}</div><div v-if="t.status !== 'pending'" class="mt-0.5">{{ t.status === 'approved' ? '✅' : t.status === 'editing' ? '✏️' : '❌' }} {{ t.approver?.name || t.modifier_name || '—' }}</div></td>
                            <td data-label="" class="px-5 py-3 text-center space-x-1 space-x-reverse actions-cell">
                                <template v-if="t.status==='pending'">
                                    <button v-if="can('transfers.approve')" @click="approveItem(t)" class="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded-lg btn-mobile-sm">✅ اعتماد</button>
                                    <button v-if="can('transfers.reject')" @click="rejectTarget=t" class="px-2 py-1 text-xs text-orange-600 hover:bg-orange-50 rounded-lg btn-mobile-sm">❌ رفض</button>
                                    <button v-if="can('transfers.delete')" @click="deleteTarget=t" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️</button>
                                </template>
                                <template v-else-if="t.status==='approved'">
                                    <button v-if="can('transfers.edit_approved')" @click="startEditApproved(t)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg btn-mobile-sm">✏️ تعديل</button>
                                    <a :href="'/transfers/'+t.id+'/print'" target="_blank" class="px-2 py-1 text-xs text-purple-600 hover:bg-purple-50 rounded-lg btn-mobile-sm">🖨️</a>
                                </template>
                                <template v-else-if="t.status==='editing'">
                                    <button @click="openEditForm(t)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg btn-mobile-sm">📝 تعديل وإرسال</button>
                                </template>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="!transfers.data?.length"><td colspan="9" class="px-5 py-12 text-center text-gray-400">لا يوجد حوالات</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create/Edit Form Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl mx-4 p-6 max-h-[90vh] overflow-y-auto modal-responsive">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ editingTransfer ? 'تعديل حوالة' : 'حوالة جديدة' }}</h3><button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submitForm" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">الوكيل *</label><SearchableSelect v-model="form.agent_id" :options="agentOptions" placeholder="اختر الوكيل" search-placeholder="ابحث عن وكيل..." /><p v-if="form.errors.agent_id" class="mt-1 text-xs text-red-500">{{ form.errors.agent_id }}</p></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">المبلغ (SAR) *</label><input v-model="form.amount_sar" type="number" step="0.01" min="0.01" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">التكلفة (JOD) *</label><input v-model="form.cost_jod" type="number" step="0.001" min="0.001" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">طريقة الدفع *</label><select v-model="form.payment_method" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500"><option value="cash">نقدي</option><option value="bank">تحويل بنكي</option><option value="check">شيك</option></select></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">المرجع</label><input v-model="form.reference_number" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                    </div>

                    <!-- عرض الفرق -->
                    <div v-if="difference !== 0" class="p-4 rounded-xl border text-sm" :class="difference > 0 ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700'">
                        <div class="font-bold mb-2">
                            {{ difference > 0 ? '📉 فرق مصروف' : '📈 فرق إيراد' }}:
                            <span class="font-mono text-lg" dir="ltr">{{ Math.abs(difference).toFixed(3) }} JOD</span>
                        </div>
                        <!-- اختيار تصنيف المصروف -->
                        <div v-if="difference > 0" class="mt-2">
                            <label class="block text-xs font-medium mb-1">تصنيف المصروف</label>
                            <select v-model="form.expense_category_id" class="w-full px-3 py-2 rounded-lg border border-red-200 text-sm focus:ring-2 focus:ring-red-400">
                                <option value="">— عام —</option>
                                <option v-for="cat in expenseCategories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                        </div>
                        <!-- اختيار حساب الإيراد -->
                        <div v-if="difference < 0" class="mt-2">
                            <label class="block text-xs font-medium mb-1">حساب الإيراد</label>
                            <select v-model="form.revenue_account_id" class="w-full px-3 py-2 rounded-lg border border-green-200 text-sm focus:ring-2 focus:ring-green-400">
                                <option value="">— إيرادات أخرى —</option>
                                <option v-for="acc in revenueAccounts" :key="acc.id" :value="acc.id">{{ acc.code }} — {{ acc.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div><label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label><textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea></div>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">{{ editingTransfer ? '💾 تعديل وإرسال' : '✅ إنشاء' }}</button>
                        <button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reject Modal -->
        <div v-if="rejectTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="rejectTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">سبب الرفض</h3>
                <textarea v-model="rejectReason" rows="3" placeholder="اذكر سبب الرفض..." class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none mb-4"></textarea>
                <div class="flex gap-3">
                    <button @click="submitReject" :disabled="!rejectReason" class="px-5 py-2.5 rounded-xl font-bold text-sm text-white bg-orange-500 hover:bg-orange-600 disabled:opacity-50">رفض</button>
                    <button @click="rejectTarget=null" class="px-5 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                </div>
            </div>
        </div>

        <!-- Delete Modal -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">حذف الحوالة</h3>
                <p class="text-sm text-gray-500 mb-6">{{ deleteTarget.transfer_number }}</p>
                <div class="flex gap-3 justify-center">
                    <button @click="confirmDelete" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600">🗑️ حذف</button>
                    <button @click="deleteTarget=null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref, computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useHighlight } from '@/composables/useHighlight';
const { can } = usePermissions();
const { isHighlighted } = useHighlight();

const EXCHANGE_RATE = 0.19;

const props = defineProps({
    transfers: Object, filters: Object, agents: Array,
    expenseCategories: Array, revenueAccounts: Array,
});

const agentOptions = computed(() => props.agents.map(a => ({ value: a.id, label: `${a.name} (${a.code})` })));
const search = ref(props.filters?.search||'');
const statusFilter = ref(props.filters?.status||'');
const showForm = ref(false);
const editingTransfer = ref(null);
const rejectTarget = ref(null);
const rejectReason = ref('');
const deleteTarget = ref(null);
let t=null;

const statusLabels = { pending:'معلقة', approved:'معتمدة', rejected:'مرفوضة', editing:'تحت التعديل' };
const statusClasses = { pending:'bg-yellow-100 text-yellow-700', approved:'bg-green-100 text-green-700', rejected:'bg-red-100 text-red-700', editing:'bg-blue-100 text-blue-700' };
const payMethods = { cash:'نقدي', bank:'تحويل بنكي', check:'شيك' };

const form = useForm({
    agent_id:'', amount_sar:'', cost_jod:'',
    payment_method:'cash', reference_number:'', notes:'',
    expense_category_id:'', revenue_account_id:'',
});

// حساب الفرق بالدينار: cost_jod - (amount_sar * 0.19)
const difference = computed(() => {
    const sar = parseFloat(form.amount_sar) || 0;
    const jod = parseFloat(form.cost_jod) || 0;
    if (sar <= 0 || jod <= 0) return 0;
    const sarInJod = sar * EXCHANGE_RATE;  // تحويل الريال إلى دينار
    return Math.round((jod - sarInJod) * 1000) / 1000;
});

const diffClass = (t) => {
    if (t.difference_type === 'expense') return 'text-red-600 font-bold';
    if (t.difference_type === 'revenue') return 'text-green-600 font-bold';
    return 'text-gray-400';
};

const openForm = () => { editingTransfer.value = null; form.reset(); form.clearErrors(); showForm.value=true; };

const openEditForm = (transfer) => {
    editingTransfer.value = transfer;
    form.agent_id = transfer.agent_id;
    form.amount_sar = transfer.amount_sar;
    form.cost_jod = transfer.cost_jod;
    form.payment_method = transfer.payment_method;
    form.reference_number = transfer.reference_number || '';
    form.notes = transfer.notes || '';
    form.expense_category_id = transfer.expense_category_id || '';
    form.revenue_account_id = transfer.revenue_account_id || '';
    form.clearErrors();
    showForm.value = true;
};

const submitForm = () => {
    if (editingTransfer.value) {
        form.put('/transfers/'+editingTransfer.value.id+'/update-approved', {
            onSuccess:()=>{ showForm.value=false; form.reset(); form.clearErrors(); editingTransfer.value=null; },
            preserveScroll:true, preserveState:false
        });
    } else {
        form.post('/transfers', {
            onSuccess:()=>{ showForm.value=false; form.reset(); form.clearErrors(); },
            preserveScroll:true, preserveState:false
        });
    }
};

const approveItem = (t) => { router.post('/transfers/'+t.id+'/approve', {}, {preserveScroll:true}); };
const startEditApproved = (t) => { router.post('/transfers/'+t.id+'/start-edit', {}, {preserveScroll:true}); };
const submitReject = () => { router.post('/transfers/'+rejectTarget.value.id+'/reject', { rejection_reason: rejectReason.value }, { preserveScroll:true, onSuccess:()=>{rejectTarget.value=null;rejectReason.value='';} }); };
const confirmDelete = () => { router.delete('/transfers/'+deleteTarget.value.id, { preserveScroll:true, onSuccess:()=>{deleteTarget.value=null;} }); };
const debounceSearch = () => { clearTimeout(t); t=setTimeout(()=>applyFilter(),400); };
const applyFilter = () => { router.get('/transfers', { search:search.value, status:statusFilter.value }, { preserveState:true, replace:true }); };
</script>
