<template>
    <AppLayout>
        <template #header>المصروفات</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <input v-model="search" type="text" placeholder="بحث..." class="w-64 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                <button v-if="can('expenses.create')" @click="openForm()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md w-full sm:w-auto">+ مصروف جديد</button>
            </div>
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white"><table class="w-full text-sm responsive-table">
                <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400"><th class="px-5 py-3 text-right font-bold">الرقم</th><th class="px-5 py-3 text-right font-bold hide-mobile">التصنيف</th><th class="px-5 py-3 text-right font-bold">الوصف</th><th class="px-5 py-3 text-right font-bold">المبلغ</th><th class="px-5 py-3 text-right font-bold">الحالة</th><th class="px-5 py-3 text-right font-bold hide-mobile">بواسطة</th><th class="px-5 py-3 text-center font-bold">إجراءات</th></tr></thead>
                <tbody><tr v-for="e in expenses.data" :key="e.id" :data-row-id="e.id"
                            class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30"
                            :class="{ 'row-glow': isHighlighted(e.id) }">
                    <td data-label="الرقم" class="px-5 py-3 text-right font-mono text-xs text-gold-700">{{ e.expense_number }}</td>
                    <td data-label="التصنيف" class="px-5 py-3 text-right text-xs hide-mobile">{{ e.category?.name || '—' }}</td>
                    <td data-label="الوصف" class="px-5 py-3 text-right text-xs max-w-[200px] truncate">{{ e.description }}</td>
                    <td data-label="المبلغ" class="px-5 py-3 text-right font-bold font-mono text-xs text-green-600" dir="ltr">{{ Number(e.amount).toLocaleString('en',{minimumFractionDigits:2}) }} {{ e.currency }}</td>
                    <td data-label="الحالة" class="px-5 py-3 text-right"><span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="{pending:'bg-yellow-100 text-yellow-700',approved:'bg-green-100 text-green-700',rejected:'bg-red-100 text-red-700',editing:'bg-blue-100 text-blue-700'}[e.status]">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة',editing:'تحت التعديل'}[e.status] }}</span></td>
                    <td data-label="بواسطة" class="px-5 py-3 text-right text-xs text-gray-500 hide-mobile"><div>📝 {{ e.creator?.name || '—' }}</div><div v-if="e.status !== 'pending'" class="mt-0.5">{{ e.status === 'approved' ? '✅' : '❌' }} {{ e.approver?.name || '—' }}</div></td>
                    <td data-label="" class="px-5 py-3 text-center whitespace-nowrap actions-cell">
                        <a :href="'/expenses/'+e.id+'/print'" target="_blank" class="px-2 py-1 text-xs text-purple-600 hover:bg-purple-50 rounded-lg btn-mobile-sm">🖨️</a>
                        <template v-if="e.status==='pending'"><button v-if="can('expenses.approve')" @click="router.post('/expenses/'+e.id+'/approve')" class="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded-lg btn-mobile-sm">✅</button><button v-if="can('expenses.delete')" @click="router.delete('/expenses/'+e.id)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️</button></template>
                        <template v-if="e.status==='approved'"><button v-if="can('expenses.edit_approved')" @click="startEdit(e)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg btn-mobile-sm">✏️ تعديل</button></template>
                        <template v-if="e.status==='editing'"><button @click="openEditForm(e)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg font-bold btn-mobile-sm">📝 تعديل البيانات</button></template>
                    </td>
                </tr><tr v-if="!expenses.data?.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا يوجد مصروفات</td></tr></tbody>
            </table></div>
        </div>

        <!-- فورم إنشاء مصروف جديد -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl mx-4 p-6 modal-responsive">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold">مصروف جديد</h3><button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="form.post('/expenses',{onSuccess:()=>{showForm=false; form.reset(); form.clearErrors();},preserveState:false})" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4 mobile-form-grid">
                        <div><label class="block text-sm font-medium mb-1">التصنيف *</label><SearchableSelect v-model="form.category_id" :options="categoryOptions" placeholder="اختر التصنيف" search-placeholder="ابحث..." /></div>
                        <div><label class="block text-sm font-medium mb-1">المبلغ *</label><input v-model="form.amount" type="number" step="0.01" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border text-sm"/></div>
                        <div><label class="block text-sm font-medium mb-1">العملة *</label><select v-model="form.currency" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="JOD">JOD</option><option value="SAR">SAR</option></select></div>
                        <div><label class="block text-sm font-medium mb-1">طريقة الدفع *</label><select v-model="form.payment_method" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="cash">نقدي</option><option value="bank">بنكي</option><option value="check">شيك</option></select></div>
                    </div>
                    <div><label class="block text-sm font-medium mb-1">الوصف *</label><textarea v-model="form.description" rows="2" required class="w-full px-4 py-2.5 rounded-xl border text-sm resize-none"></textarea></div>
                    <div><label class="block text-sm font-medium mb-1">ملاحظات</label><textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border text-sm resize-none"></textarea></div>
                    <div class="flex gap-3"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">✅ إنشاء</button><button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button></div>
                </form>
            </div>
        </div>

        <!-- فورم تعديل مصروف معتمد -->
        <div v-if="showEditForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showEditForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xl mx-4 p-6">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-blue-600">✏️ تعديل مصروف {{ editForm.expense_number }}</h3><button @click="showEditForm=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium mb-1">التصنيف *</label><SearchableSelect v-model="editForm.category_id" :options="categoryOptions" placeholder="اختر التصنيف" search-placeholder="ابحث..." /></div>
                        <div><label class="block text-sm font-medium mb-1">المبلغ *</label><input v-model="editForm.amount" type="number" step="0.01" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border text-sm"/></div>
                        <div><label class="block text-sm font-medium mb-1">العملة *</label><select v-model="editForm.currency" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="JOD">JOD</option><option value="SAR">SAR</option></select></div>
                        <div><label class="block text-sm font-medium mb-1">طريقة الدفع *</label><select v-model="editForm.payment_method" class="w-full px-4 py-2.5 rounded-xl border text-sm"><option value="cash">نقدي</option><option value="bank">بنكي</option><option value="check">شيك</option></select></div>
                    </div>
                    <div><label class="block text-sm font-medium mb-1">الوصف *</label><textarea v-model="editForm.description" rows="2" required class="w-full px-4 py-2.5 rounded-xl border text-sm resize-none"></textarea></div>
                    <div><label class="block text-sm font-medium mb-1">ملاحظات</label><textarea v-model="editForm.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border text-sm resize-none"></textarea></div>
                    <div class="p-3 bg-blue-50 rounded-xl text-xs text-blue-700">⚠️ بعد التعديل سيتم إرسال المصروف للاعتماد مرة أخرى</div>
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
const props = defineProps({ expenses: Object, filters: Object, categories: Array });
const categoryOptions = computed(() => props.categories.map(c => ({ value: c.id, label: c.name })));
const search = ref(''); const showForm = ref(false); const showEditForm = ref(false); let t=null;
const form = useForm({ category_id:'', description:'', amount:'', currency:'JOD', payment_method:'cash', notes:'' });
const editForm = useForm({ _editId: null, expense_number:'', category_id:'', description:'', amount:'', currency:'JOD', payment_method:'cash', notes:'' });

const openForm=()=>{form.reset();showForm.value=true;};

const startEdit = (e) => {
    if(confirm('تعديل المصروف المعتمد؟ سيتم عكس الأثر المالي.')) {
        router.post('/expenses/'+e.id+'/start-edit',{},{preserveScroll:true});
    }
};

const openEditForm = (e) => {
    editForm._editId = e.id;
    editForm.expense_number = e.expense_number;
    editForm.category_id = e.category_id;
    editForm.description = e.description;
    editForm.amount = e.amount;
    editForm.currency = e.currency;
    editForm.payment_method = e.payment_method;
    editForm.notes = e.notes || '';
    showEditForm.value = true;
};

const submitEdit = () => {
    editForm.put('/expenses/'+editForm._editId+'/update-approved', {
        onSuccess: () => { showEditForm.value = false; editForm.reset(); },
        preserveScroll: true,
    });
};

const debounceSearch=()=>{clearTimeout(t);t=setTimeout(()=>router.get('/expenses',{search:search.value},{preserveState:true,replace:true}),400);};
</script>
