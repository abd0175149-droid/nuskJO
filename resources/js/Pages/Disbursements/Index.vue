<template>
    <AppLayout>
        <template #header>سندات الصرف</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <input v-model="search" type="text" placeholder="بحث..." class="w-64 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                <button v-if="can('disbursements.create')" @click="openForm()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md w-full sm:w-auto">+ سند صرف</button>
            </div>
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white"><table class="w-full text-sm responsive-table">
                <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400"><th class="px-5 py-3 text-right font-bold">الرقم</th><th class="px-5 py-3 text-right font-bold">الحساب</th><th class="px-5 py-3 text-right font-bold">المبلغ</th><th class="px-5 py-3 text-right font-bold hide-mobile">الدفع</th><th class="px-5 py-3 text-right font-bold hide-mobile">التاريخ</th><th class="px-5 py-3 text-right font-bold">الحالة</th><th class="px-5 py-3 text-right font-bold hide-mobile">بواسطة</th><th class="px-5 py-3 text-center font-bold">إجراءات</th></tr></thead>
                <tbody><tr v-for="d in disbursements.data" :key="d.id" :data-row-id="d.id"
                            class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30"
                            :class="{ 'row-glow': isHighlighted(d.id) }">
                    <td data-label="الرقم" class="px-5 py-3 text-right font-mono text-xs text-gold-700">{{ d.disbursement_number }}</td>
                    <td data-label="الحساب" class="px-5 py-3 text-right">
                        <div class="text-xs font-medium">{{ d.account?.name }}</div>
                        <div class="text-[10px] text-gray-400 font-mono">{{ d.account?.code }}</div>
                    </td>
                    <td data-label="المبلغ" class="px-5 py-3 text-right font-bold font-mono text-xs" :class="d.currency==='SAR'?'text-blue-600':'text-green-600'" dir="ltr">{{ Number(d.amount).toLocaleString('en',{minimumFractionDigits:d.currency==='SAR'?2:3}) }} {{ d.currency }}</td>
                    <td data-label="الدفع" class="px-5 py-3 text-right text-xs hide-mobile">{{ {cash:'نقدي',bank:'بنكي',check:'شيك'}[d.payment_method] }}</td>
                    <td data-label="التاريخ" class="px-5 py-3 text-right font-mono text-xs text-gray-500 hide-mobile" dir="ltr">{{ d.disbursement_date }}</td>
                    <td data-label="الحالة" class="px-5 py-3 text-right"><span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="{pending:'bg-yellow-100 text-yellow-700',approved:'bg-green-100 text-green-700',rejected:'bg-red-100 text-red-700',editing:'bg-blue-100 text-blue-700'}[d.status]">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة',editing:'تحت التعديل'}[d.status] }}</span></td>
                    <td data-label="بواسطة" class="px-5 py-3 text-right text-xs text-gray-500 hide-mobile"><div>📝 {{ d.creator?.name || '—' }}</div><div v-if="d.status !== 'pending'" class="mt-0.5">{{ d.status === 'approved' ? '✅' : '❌' }} {{ d.approver?.name || '—' }}</div></td>
                    <td data-label="" class="px-5 py-3 text-center whitespace-nowrap actions-cell">
                        <a :href="'/disbursements/'+d.id+'/print'" target="_blank" class="px-2 py-1 text-xs text-purple-600 hover:bg-purple-50 rounded-lg btn-mobile-sm">🖨️</a>
                        <template v-if="d.status==='pending'"><button v-if="can('disbursements.approve')" @click="router.post('/disbursements/'+d.id+'/approve')" class="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded-lg btn-mobile-sm">✅</button><button v-if="can('disbursements.delete')" @click="router.delete('/disbursements/'+d.id)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️</button></template>
                        <template v-if="d.status==='approved'"><button v-if="can('disbursements.edit_approved')" @click="startEdit(d)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg btn-mobile-sm">✏️ تعديل</button></template>
                        <template v-if="d.status==='editing'"><button @click="openEditForm(d)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg font-bold btn-mobile-sm">📝 تعديل البيانات</button></template>
                    </td>
                </tr><tr v-if="!disbursements.data?.length"><td colspan="8" class="px-5 py-12 text-center text-gray-400">لا يوجد سندات</td></tr></tbody>
            </table></div>
        </div>

        <!-- فورم إنشاء سند صرف جديد -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl mx-4 p-6 max-h-[90vh] overflow-y-auto modal-responsive">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold">📤 سند صرف جديد</h3><button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="form.post('/disbursements',{onSuccess:()=>{showForm=false; form.reset(); form.clearErrors();},preserveState:false})" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4 mobile-form-grid">
                        <div class="col-span-2"><label class="block text-sm font-medium mb-1">الحساب (من شجرة الحسابات) *</label><SearchableSelect v-model="form.account_id" :options="accountOptions" placeholder="اختر الحساب" search-placeholder="ابحث بالاسم أو الكود..." /></div>
                        <div><label class="block text-sm font-medium mb-1">المبلغ *</label><input v-model="form.amount" type="number" :step="form.currency==='SAR'?'0.01':'0.001'" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border text-sm"/></div>
                        <div><label class="block text-sm font-medium mb-1">العملة *</label><select v-model="form.currency" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="JOD">دينار أردني JOD</option><option value="SAR">ريال سعودي SAR</option></select></div>
                        <div><label class="block text-sm font-medium mb-1">طريقة الدفع *</label><select v-model="form.payment_method" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="cash">نقدي</option><option value="bank">بنكي</option><option value="check">شيك</option></select></div>
                    </div>
                    <div><label class="block text-sm font-medium mb-1">الوصف *</label><input v-model="form.description" type="text" required class="w-full px-4 py-2.5 rounded-xl border text-sm"/></div>
                    <div><label class="block text-sm font-medium mb-1">ملاحظات</label><textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border text-sm resize-none"></textarea></div>
                    <div class="flex gap-3"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">✅ إنشاء</button><button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button></div>
                </form>
            </div>
        </div>

        <!-- فورم تعديل سند معتمد -->
        <div v-if="showEditForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showEditForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-blue-600">✏️ تعديل سند {{ editForm.disbursement_number }}</h3><button @click="showEditForm=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4 mobile-form-grid">
                        <div class="col-span-2"><label class="block text-sm font-medium mb-1">الحساب *</label><SearchableSelect v-model="editForm.account_id" :options="accountOptions" placeholder="اختر الحساب" search-placeholder="ابحث..." /></div>
                        <div><label class="block text-sm font-medium mb-1">المبلغ *</label><input v-model="editForm.amount" type="number" step="0.001" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border text-sm"/></div>
                        <div><label class="block text-sm font-medium mb-1">العملة *</label><select v-model="editForm.currency" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="JOD">JOD</option><option value="SAR">SAR</option></select></div>
                        <div><label class="block text-sm font-medium mb-1">طريقة الدفع *</label><select v-model="editForm.payment_method" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="cash">نقدي</option><option value="bank">بنكي</option><option value="check">شيك</option></select></div>
                    </div>
                    <div><label class="block text-sm font-medium mb-1">الوصف *</label><input v-model="editForm.description" type="text" required class="w-full px-4 py-2.5 rounded-xl border text-sm"/></div>
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
const props = defineProps({ disbursements: Object, filters: Object, accounts: Array });

const accountOptions = computed(() => props.accounts.map(a => ({
    value: a.id,
    label: `${a.code} — ${a.name} (${({asset:'أصول',liability:'التزامات',equity:'ملكية',revenue:'إيرادات',expense:'مصروفات'})[a.type] || a.type})`,
})));

const search = ref(''); const showForm = ref(false); const showEditForm = ref(false); let t=null;
const form = useForm({ account_id:'', amount:'', currency:'JOD', payment_method:'cash', description:'', notes:'' });
const editForm = useForm({ _editId: null, disbursement_number:'', account_id:'', amount:'', currency:'JOD', payment_method:'cash', description:'', notes:'' });

const openForm=()=>{form.reset();showForm.value=true;};

const startEdit = (d) => {
    if(confirm('تعديل السند المعتمد؟ سيتم عكس الأثر المالي.')) {
        router.post('/disbursements/'+d.id+'/start-edit',{},{preserveScroll:true});
    }
};

const openEditForm = (d) => {
    editForm._editId = d.id;
    editForm.disbursement_number = d.disbursement_number;
    editForm.account_id = d.account_id;
    editForm.amount = d.amount;
    editForm.currency = d.currency;
    editForm.payment_method = d.payment_method;
    editForm.description = d.description;
    editForm.notes = d.notes || '';
    showEditForm.value = true;
};

const submitEdit = () => {
    editForm.put('/disbursements/'+editForm._editId+'/update-approved', {
        onSuccess: () => { showEditForm.value = false; editForm.reset(); },
        preserveScroll: true,
    });
};

const debounceSearch=()=>{clearTimeout(t);t=setTimeout(()=>router.get('/disbursements',{search:search.value},{preserveState:true,replace:true}),400);};
</script>
