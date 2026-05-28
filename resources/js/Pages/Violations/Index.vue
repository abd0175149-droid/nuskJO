<template>
    <AppLayout>
        <template #header>المخالفات</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <div class="flex items-center gap-3 flex-wrap">
                    <input v-model="search" type="text" placeholder="بحث بالرقم أو الجواز..." class="w-64 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                    <select v-model="statusFilter" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter"><option value="">كل الحالات</option><option value="pending">معلقة</option><option value="approved">معتمدة</option><option value="rejected">مرفوضة</option></select>
                    <select v-model="billingFilter" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter"><option value="">كل الفوترة</option><option value="unbilled">غير مفوترة</option><option value="billed">مفوترة</option></select>
                </div>
                <button v-if="can('violations.create')" @click="openModal()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md w-full sm:w-auto">+ تسجيل مخالفة</button>
            </div>
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm responsive-table">
                <thead><tr class="bg-gray-50 text-gray-600">
                    <th class="px-4 py-3 text-right font-bold">الرقم</th>
                    <th class="px-4 py-3 text-right font-bold">الوكيل</th>
                    <th class="px-4 py-3 text-right font-bold hide-mobile">العميل</th>
                    <th class="px-4 py-3 text-right font-bold">النوع</th>
                    <th class="px-4 py-3 text-right font-bold hide-mobile">الجواز</th>
                    <th class="px-4 py-3 text-right font-bold">التكلفة</th>
                    <th class="px-4 py-3 text-right font-bold">الحالة</th>
                    <th class="px-4 py-3 text-right font-bold hide-mobile">الفوترة</th>
                    <th class="px-4 py-3 text-right font-bold hide-mobile">بواسطة</th>
                    <th class="px-4 py-3 text-center font-bold">إجراءات</th>
                </tr></thead>
                <tbody>
                    <tr v-for="v in violations.data" :key="v.id" :data-row-id="v.id"
                            class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30"
                            :class="{ 'row-glow': isHighlighted(v.id) }">
                        <td data-label="الرقم" class="px-4 py-3 text-right font-mono text-xs text-gold-700">{{ v.violation_number }}</td>
                        <td data-label="الوكيل" class="px-4 py-3 text-right text-gray-800 dark:text-gray-200 text-xs">{{ v.agent?.name||'—' }}</td>
                        <td data-label="العميل" class="px-4 py-3 text-right text-gray-600 dark:text-gray-400 text-xs hide-mobile">{{ v.client?.name||'—' }}</td>
                        <td data-label="النوع" class="px-4 py-3 text-right text-xs">{{ v.violation_type?.name||'—' }}</td>
                        <td data-label="الجواز" class="px-4 py-3 text-right font-mono text-xs hide-mobile" dir="ltr">{{ v.passport_number||'—' }}</td>
                        <td data-label="التكلفة" class="px-4 py-3 text-right font-bold font-mono text-xs text-red-600" dir="ltr">{{ Number(v.cost_sar).toLocaleString('en',{minimumFractionDigits:2}) }} SAR</td>
                        <td data-label="الحالة" class="px-4 py-3 text-right"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="{'bg-yellow-100 text-yellow-700':v.status==='pending','bg-green-100 text-green-700':v.status==='approved','bg-red-100 text-red-700':v.status==='rejected','bg-blue-100 text-blue-700':v.status==='editing'}">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة',editing:'تحت التعديل'}[v.status] }}</span></td>
                        <td data-label="الفوترة" class="px-4 py-3 text-right hide-mobile"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="v.billing_status==='billed'?'bg-blue-100 text-blue-700':'bg-gray-100 text-gray-600'">{{ v.billing_status==='billed'?'مفوترة':'غير مفوترة' }}</span></td>
                        <td data-label="بواسطة" class="px-4 py-3 text-right text-xs text-gray-500 hide-mobile"><div>📝 {{ v.creator?.name || '—' }}</div><div v-if="v.status !== 'pending'" class="mt-0.5">{{ v.status === 'approved' ? '✅' : '❌' }} {{ v.approver?.name || '—' }}</div></td>
                        <td data-label="" class="px-4 py-3 text-center whitespace-nowrap actions-cell">
                            <button v-if="v.status==='pending' && can('violations.approve')" @click="approveVio(v)" class="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded-lg btn-mobile-sm">✅</button>
                            <button v-if="v.status==='pending' && can('violations.reject')" @click="rejectVio(v)" class="px-2 py-1 text-xs text-orange-600 hover:bg-orange-50 rounded-lg btn-mobile-sm">❌</button>
                            <button v-if="v.status==='pending' && can('violations.delete')" @click="del(v)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️</button>
                            <button v-if="v.status==='approved' && can('violations.edit_approved')" @click="startEditVio(v)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg btn-mobile-sm">✏️ تعديل</button>
                            <button v-if="v.status==='editing'" @click="openEditForm(v)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg font-bold btn-mobile-sm">📝 تعديل البيانات</button>
                        </td>
                    </tr>
                    <tr v-if="!violations.data?.length"><td colspan="10" class="px-5 py-12 text-center text-gray-400">لا يوجد مخالفات</td></tr>
                </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Form Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">تسجيل مخالفة جديدة</h3><button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">الوكيل *</label><SearchableSelect v-model="form.agent_id" :options="agentOptions" placeholder="اختر الوكيل" search-placeholder="ابحث عن وكيل..." /></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">العميل *</label><SearchableSelect v-model="form.client_id" :options="clientOptions" placeholder="اختر العميل" search-placeholder="ابحث عن عميل..." /></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">نوع المخالفة *</label><SearchableSelect v-model="form.violation_type_id" :options="vtOptions" placeholder="اختر النوع" search-placeholder="ابحث..." @change="onTypeChange" /></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">التكلفة (SAR) *</label><input v-model="form.cost_sar" type="number" step="0.01" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">تاريخ المخالفة *</label><input v-model="form.violation_date" type="date" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">رقم الجواز</label><input v-model="form.passport_number" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                        <div class="md:col-span-2"><label class="block text-sm font-medium text-gray-700 mb-1">اسم صاحب الجواز</label><input v-model="form.passport_name" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">الوصف</label><textarea v-model="form.description" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label><textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea></div>
                    <div class="flex gap-3"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">✅ تسجيل المخالفة</button><button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button></div>
                </form>
            </div>
        </div>

        <!-- Delete Modal -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">تأكيد الحذف</h3>
                <p class="text-sm text-gray-500 mb-6">حذف المخالفة <strong class="text-gray-800 dark:text-gray-100">{{ deleteTarget.violation_number }}</strong>؟</p>
                <div class="flex gap-3 justify-center">
                    <button @click="confirmDel" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600">🗑️ حذف</button>
                    <button @click="deleteTarget=null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                </div>
            </div>
        </div>

        <!-- فورم تعديل مخالفة معتمدة -->
        <div v-if="showEditForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showEditForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-blue-600">✏️ تعديل مخالفة {{ editForm.violation_number }}</h3><button @click="showEditForm=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submitEditVio" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">الوكيل *</label><SearchableSelect v-model="editForm.agent_id" :options="agentOptions" placeholder="اختر الوكيل" search-placeholder="ابحث عن وكيل..." /></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">العميل *</label><SearchableSelect v-model="editForm.client_id" :options="clientOptions" placeholder="اختر العميل" search-placeholder="ابحث عن عميل..." /></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">نوع المخالفة *</label><SearchableSelect v-model="editForm.violation_type_id" :options="vtOptions" placeholder="اختر النوع" search-placeholder="ابحث..." /></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">التكلفة (SAR) *</label><input v-model="editForm.cost_sar" type="number" step="0.01" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">تاريخ المخالفة *</label><input v-model="editForm.violation_date" type="date" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">رقم الجواز</label><input v-model="editForm.passport_number" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm"/></div>
                        <div class="md:col-span-2"><label class="block text-sm font-medium text-gray-700 mb-1">اسم صاحب الجواز</label><input v-model="editForm.passport_name" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm"/></div>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">الوصف</label><textarea v-model="editForm.description" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm resize-none"></textarea></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label><textarea v-model="editForm.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm resize-none"></textarea></div>
                    <div class="p-3 bg-blue-50 rounded-xl text-xs text-blue-700">⚠️ بعد التعديل سيتم إرسال المخالفة للاعتماد مرة أخرى</div>
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
import { usePermissions } from '@/composables/usePermissions';
import { useHighlight } from '@/composables/useHighlight';
const { can } = usePermissions();
const { isHighlighted } = useHighlight();
import SearchableSelect from '@/Components/SearchableSelect.vue';

const props = defineProps({ violations: Object, filters: Object, agents: Array, clients: Array, violationTypes: Array });
const agentOptions = computed(() => props.agents.map(a => ({ value: a.id, label: `${a.name} (${a.code})` })));
const clientOptions = computed(() => props.clients.map(c => ({ value: c.id, label: `${c.name} (${c.code})` })));
const vtOptions = computed(() => props.violationTypes.map(v => ({ value: v.id, label: v.name })));
const search = ref(props.filters?.search||'');
const statusFilter = ref(props.filters?.status||'');
const billingFilter = ref(props.filters?.billing||'');
const showForm = ref(false);
const showEditForm = ref(false);
const deleteTarget = ref(null);
let t = null;

const today = new Date().toISOString().split('T')[0];
const form = useForm({ agent_id:'', client_id:'', violation_type_id:'', cost_sar:'', violation_date:today, passport_number:'', passport_name:'', description:'', notes:'' });
const editForm = useForm({ _editId:null, violation_number:'', agent_id:'', client_id:'', violation_type_id:'', cost_sar:'', violation_date:'', passport_number:'', passport_name:'', description:'', notes:'' });

const openModal = () => { form.reset(); form.violation_date = today; form.clearErrors(); showForm.value = true; };

const onTypeChange = () => {
    const vt = props.violationTypes?.find(v => v.id == form.violation_type_id);
    if (vt) form.cost_sar = vt.default_cost_sar;
};

const submit = () => { form.post('/violations', { onSuccess: () => { showForm.value = false; form.reset(); form.clearErrors(); }, preserveScroll: true, preserveState: false }); };

const approveVio = (v) => { if (confirm('اعتماد المخالفة وخصم ' + v.cost_sar + ' SAR من الوكيل؟')) router.post('/violations/' + v.id + '/approve', {}, { preserveScroll: true }); };
const rejectVio = (v) => { const r = prompt('سبب الرفض:'); if (r !== null) router.post('/violations/' + v.id + '/reject', { reason: r }, { preserveScroll: true }); };
const del = (v) => { deleteTarget.value = v; };
const confirmDel = () => { router.delete('/violations/' + deleteTarget.value.id, { preserveScroll: true, onSuccess: () => { deleteTarget.value = null; } }); };

const startEditVio = (v) => {
    if(confirm('تعديل المخالفة المعتمدة؟ سيتم عكس الأثر المالي.')) {
        router.post('/violations/'+v.id+'/start-edit',{},{preserveScroll:true});
    }
};

const openEditForm = (v) => {
    editForm._editId = v.id;
    editForm.violation_number = v.violation_number;
    editForm.agent_id = v.agent_id;
    editForm.client_id = v.client_id;
    editForm.violation_type_id = v.violation_type_id;
    editForm.cost_sar = v.cost_sar;
    editForm.violation_date = v.violation_date?.split('T')[0] || '';
    editForm.passport_number = v.passport_number || '';
    editForm.passport_name = v.passport_name || '';
    editForm.description = v.description || '';
    editForm.notes = v.notes || '';
    showEditForm.value = true;
};

const submitEditVio = () => {
    editForm.put('/violations/'+editForm._editId+'/update-approved', {
        onSuccess: () => { showEditForm.value = false; editForm.reset(); },
        preserveScroll: true,
    });
};

const debounceSearch = () => { clearTimeout(t); t = setTimeout(() => applyFilter(), 400); };
const applyFilter = () => { router.get('/violations', { search: search.value, status: statusFilter.value, billing: billingFilter.value }, { preserveState: true, replace: true }); };
</script>
