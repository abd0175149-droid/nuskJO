<template>
    <AppLayout>
        <template #header>سندات القبض</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <input v-model="search" type="text" placeholder="بحث..." class="w-64 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                <button v-if="can('receipts.create')" @click="openForm()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md w-full sm:w-auto">+ سند قبض</button>
            </div>
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white"><table class="w-full text-sm responsive-table">
                <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400"><th class="px-5 py-3 text-right font-bold">الرقم</th><th class="px-5 py-3 text-right font-bold">العميل</th><th class="px-5 py-3 text-right font-bold">المبلغ (JOD)</th><th class="px-5 py-3 text-right font-bold hide-mobile">العمولة</th><th class="px-5 py-3 text-right font-bold hide-mobile">الدفع</th><th class="px-5 py-3 text-right font-bold">الحالة</th><th class="px-5 py-3 text-right font-bold hide-mobile">بواسطة</th><th class="px-5 py-3 text-center font-bold">إجراءات</th></tr></thead>
                <tbody><tr v-for="r in receipts.data" :key="r.id" :data-row-id="r.id"
                            class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30"
                            :class="{ 'row-glow': isHighlighted(r.id) }">
                    <td data-label="الرقم" class="px-5 py-3 text-right font-mono text-xs text-gold-700">{{ r.receipt_number }}</td>
                    <td data-label="العميل" class="px-5 py-3 text-right font-medium">{{ r.client?.name }}</td>
                    <td data-label="المبلغ JOD" class="px-5 py-3 text-right font-bold font-mono text-xs text-green-600" dir="ltr">{{ Number(r.amount_jod).toLocaleString('en',{minimumFractionDigits:3}) }}</td>
                    <td data-label="العمولة" class="px-5 py-3 text-right font-mono text-xs hide-mobile" dir="ltr">
                        <span v-if="Number(r.bank_commission) > 0" class="text-red-500 font-bold">{{ Number(r.bank_commission).toLocaleString('en',{minimumFractionDigits:3}) }}</span>
                        <span v-else class="text-gray-300">—</span>
                    </td>
                    <td data-label="الدفع" class="px-5 py-3 text-right text-xs hide-mobile">{{ {cash:'نقدي',bank:'بنكي',check:'شيك'}[r.payment_method] }}</td>
                    <td data-label="الحالة" class="px-5 py-3 text-right"><span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="{pending:'bg-yellow-100 text-yellow-700',approved:'bg-green-100 text-green-700',rejected:'bg-red-100 text-red-700',editing:'bg-blue-100 text-blue-700'}[r.status]">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة',editing:'تحت التعديل'}[r.status] }}</span></td>
                    <td data-label="بواسطة" class="px-5 py-3 text-right text-xs text-gray-500 hide-mobile"><div>📝 {{ r.creator?.name || '—' }}</div><div v-if="r.status !== 'pending'" class="mt-0.5">{{ r.status === 'approved' ? '✅' : '❌' }} {{ r.approver?.name || '—' }}</div></td>
                    <td data-label="" class="px-5 py-3 text-center whitespace-nowrap actions-cell">
                        <a :href="'/receipts/'+r.id+'/print'" target="_blank" class="px-2 py-1 text-xs text-purple-600 hover:bg-purple-50 rounded-lg btn-mobile-sm">🖨️</a>
                        <template v-if="r.status==='pending'"><button v-if="can('receipts.approve')" @click="router.post('/receipts/'+r.id+'/approve')" class="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded-lg btn-mobile-sm">✅</button><button v-if="can('receipts.delete')" @click="router.delete('/receipts/'+r.id)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️</button></template>
                        <template v-if="r.status==='approved'"><button v-if="can('receipts.edit_approved')" @click="startEdit(r)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg btn-mobile-sm">✏️ تعديل</button></template>
                        <template v-if="r.status==='editing'"><button @click="openEditForm(r)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg font-bold btn-mobile-sm">📝 تعديل البيانات</button></template>
                    </td>
                </tr><tr v-if="!receipts.data?.length"><td colspan="8" class="px-5 py-12 text-center text-gray-400">لا يوجد سندات</td></tr></tbody>
            </table></div>
        </div>

        <!-- فورم إنشاء سند جديد -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl mx-4 p-6 modal-responsive">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold">سند قبض جديد</h3><button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="form.post('/receipts',{onSuccess:()=>{showForm=false; form.reset(); form.clearErrors();},preserveState:false})" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4 mobile-form-grid">
                        <div><label class="block text-sm font-medium mb-1">العميل *</label><SearchableSelect v-model="form.client_id" :options="clientOptions" placeholder="اختر العميل" search-placeholder="ابحث عن عميل..." /></div>
                        <div><label class="block text-sm font-medium mb-1">المبلغ (JOD) *</label><input v-model="form.amount_jod" type="number" step="0.001" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border text-sm"/></div>
                        <div><label class="block text-sm font-medium mb-1">طريقة الدفع *</label><select v-model="form.payment_method" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="cash">نقدي</option><option value="bank">بنكي</option><option value="check">شيك</option></select></div>
                        <div v-if="form.payment_method === 'bank'">
                            <label class="block text-sm font-medium mb-1">عمولة البنك (JOD)</label>
                            <input v-model="form.bank_commission" type="number" step="0.001" min="0" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border text-sm border-orange-300 focus:ring-orange-500"/>
                        </div>
                    </div>
                    <!-- ملخص عند وجود عمولة -->
                    <div v-if="form.payment_method === 'bank' && Number(form.bank_commission) > 0" class="p-3 bg-orange-50 rounded-xl text-xs space-y-1">
                        <div class="flex justify-between"><span class="text-gray-600">المبلغ الكامل (يخصم من العميل):</span><span class="font-bold text-green-700">{{ Number(form.amount_jod).toLocaleString('en',{minimumFractionDigits:3}) }} JOD</span></div>
                        <div class="flex justify-between"><span class="text-gray-600">عمولة البنك (مصروف):</span><span class="font-bold text-red-600">- {{ Number(form.bank_commission).toLocaleString('en',{minimumFractionDigits:3}) }} JOD</span></div>
                        <div class="flex justify-between border-t pt-1"><span class="text-gray-600">صافي الإيراد:</span><span class="font-bold text-blue-700">{{ (Number(form.amount_jod) - Number(form.bank_commission)).toLocaleString('en',{minimumFractionDigits:3}) }} JOD</span></div>
                    </div>
                    <div><label class="block text-sm font-medium mb-1">ملاحظات</label><textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border text-sm resize-none"></textarea></div>
                    <div class="flex gap-3"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">✅ إنشاء</button><button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button></div>
                </form>
            </div>
        </div>

        <!-- فورم تعديل سند معتمد -->
        <div v-if="showEditForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showEditForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl mx-4 p-6">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-blue-600">✏️ تعديل سند {{ editForm.receipt_number }}</h3><button @click="showEditForm=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium mb-1">العميل *</label><SearchableSelect v-model="editForm.client_id" :options="clientOptions" placeholder="اختر العميل" search-placeholder="ابحث عن عميل..." /></div>
                        <div><label class="block text-sm font-medium mb-1">المبلغ (JOD) *</label><input v-model="editForm.amount_jod" type="number" step="0.001" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border text-sm"/></div>
                        <div><label class="block text-sm font-medium mb-1">طريقة الدفع *</label><select v-model="editForm.payment_method" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="cash">نقدي</option><option value="bank">بنكي</option><option value="check">شيك</option></select></div>
                        <div v-if="editForm.payment_method === 'bank'">
                            <label class="block text-sm font-medium mb-1">عمولة البنك (JOD)</label>
                            <input v-model="editForm.bank_commission" type="number" step="0.001" min="0" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border text-sm border-orange-300 focus:ring-orange-500"/>
                        </div>
                    </div>
                    <div v-if="editForm.payment_method === 'bank' && Number(editForm.bank_commission) > 0" class="p-3 bg-orange-50 rounded-xl text-xs space-y-1">
                        <div class="flex justify-between"><span class="text-gray-600">المبلغ الكامل (يخصم من العميل):</span><span class="font-bold text-green-700">{{ Number(editForm.amount_jod).toLocaleString('en',{minimumFractionDigits:3}) }} JOD</span></div>
                        <div class="flex justify-between"><span class="text-gray-600">عمولة البنك (مصروف):</span><span class="font-bold text-red-600">- {{ Number(editForm.bank_commission).toLocaleString('en',{minimumFractionDigits:3}) }} JOD</span></div>
                        <div class="flex justify-between border-t pt-1"><span class="text-gray-600">صافي الإيراد:</span><span class="font-bold text-blue-700">{{ (Number(editForm.amount_jod) - Number(editForm.bank_commission)).toLocaleString('en',{minimumFractionDigits:3}) }} JOD</span></div>
                    </div>
                    <div><label class="block text-sm font-medium mb-1">ملاحظات</label><textarea v-model="editForm.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border text-sm resize-none"></textarea></div>
                    <div class="p-3 bg-blue-50 rounded-xl text-xs text-blue-700">⚠️ بعد التعديل سيتم إرسال السند للاعتماد مرة أخرى</div>
                    <div class="flex gap-3"><button type="submit" :disabled="editForm.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50">📤 حفظ وإرسال للاعتماد</button><button type="button" @click="showEditForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button></div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useHighlight } from '@/composables/useHighlight';
const { can } = usePermissions();
const { isHighlighted } = useHighlight();
const props = defineProps({ receipts: Object, filters: Object, clients: Array });
const clientOptions = computed(() => props.clients.map(c => ({ value: c.id, label: c.name })));
const search = ref(''); const showForm = ref(false); const showEditForm = ref(false); let t=null;
const form = useForm({ client_id:'', amount_jod:'', payment_method:'cash', bank_commission:'', notes:'' });
const editForm = useForm({ _editId: null, receipt_number:'', client_id:'', amount_jod:'', payment_method:'cash', bank_commission:'', notes:'' });

const openForm=()=>{form.reset();showForm.value=true;};

const startEdit = (r) => {
    if(confirm('تعديل السند المعتمد؟ سيتم عكس الأثر المالي.')) {
        router.post('/receipts/'+r.id+'/start-edit',{},{preserveScroll:true});
    }
};

const openEditForm = (r) => {
    editForm._editId = r.id;
    editForm.receipt_number = r.receipt_number;
    editForm.client_id = r.client_id;
    editForm.amount_jod = r.amount_jod;
    editForm.payment_method = r.payment_method;
    editForm.bank_commission = r.bank_commission || '';
    editForm.notes = r.notes || '';
    showEditForm.value = true;
};

const submitEdit = () => {
    editForm.put('/receipts/'+editForm._editId+'/update-approved', {
        onSuccess: () => { showEditForm.value = false; editForm.reset(); },
        preserveScroll: true,
    });
};

const debounceSearch=()=>{clearTimeout(t);t=setTimeout(()=>router.get('/receipts',{search:search.value},{preserveState:true,replace:true}),400);};
</script>
