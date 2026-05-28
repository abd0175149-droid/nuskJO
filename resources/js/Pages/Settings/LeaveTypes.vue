<template>
    <AppLayout>
        <template #header>أنواع الإجازات</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div></div>
                <button @click="openModal()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ إضافة نوع إجازة</button>
            </div>

            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <th class="px-4 py-3 text-right font-bold">الاسم</th>
                    <th class="px-4 py-3 text-right font-bold">الرمز</th>
                    <th class="px-4 py-3 text-right font-bold">البلد</th>
                    <th class="px-4 py-3 text-right font-bold">الأيام الافتراضية</th>
                    <th class="px-4 py-3 text-right font-bold">مدفوعة</th>
                    <th class="px-4 py-3 text-right font-bold">تتطلب مرفق</th>
                    <th class="px-4 py-3 text-right font-bold">الحالة</th>
                    <th class="px-4 py-3 text-center font-bold">إجراءات</th>
                </tr></thead>
                <tbody>
                    <tr v-for="lt in leaveTypes" :key="lt.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-white text-xs">{{ lt.name }}</td>
                        <td class="px-4 py-3 text-right font-mono text-xs text-gold-700">{{ lt.code }}</td>
                        <td class="px-4 py-3 text-right text-xs">
                            <span class="px-2 py-0.5 rounded text-xs font-bold" :class="{'bg-green-100 text-green-700': lt.country==='SA', 'bg-blue-100 text-blue-700': lt.country==='JO', 'bg-purple-100 text-purple-700': lt.country==='ALL'}">{{ lt.country }}</span>
                        </td>
                        <td class="px-4 py-3 text-right text-xs">{{ lt.default_days }} يوم</td>
                        <td class="px-4 py-3 text-right"><span class="text-xs">{{ lt.is_paid ? '✅ نعم' : '❌ لا' }}</span></td>
                        <td class="px-4 py-3 text-right"><span class="text-xs">{{ lt.requires_attachment ? '📎 نعم' : '—' }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="lt.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">{{ lt.is_active ? 'نشط' : 'معطل' }}</span>
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <button @click="editItem(lt)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg">✏️</button>
                            <button @click="deleteTarget=lt" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg">🗑️</button>
                        </td>
                    </tr>
                    <tr v-if="!leaveTypes.length"><td colspan="8" class="px-5 py-12 text-center text-gray-400">لا يوجد أنواع إجازات</td></tr>
                </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Form Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ isEditing ? 'تعديل نوع إجازة' : 'إضافة نوع إجازة' }}</h3><button @click="showForm=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الاسم *</label><input v-model="form.name" type="text" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الرمز *</label><input v-model="form.code" type="text" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">البلد *</label>
                            <select v-model="form.country" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                                <option value="SA">السعودية (SA)</option>
                                <option value="JO">الأردن (JO)</option>
                                <option value="ALL">الكل (ALL)</option>
                            </select>
                        </div>
                        <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الأيام الافتراضية *</label><input v-model="form.default_days" type="number" min="0" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الحد الأقصى للأيام المتتالية</label><input v-model="form.max_consecutive_days" type="number" min="1" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"/></div>
                    <div class="flex flex-wrap gap-6">
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" v-model="form.is_paid" class="rounded border-gray-300 text-gold-600"><span class="text-sm">مدفوعة</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" v-model="form.requires_attachment" class="rounded border-gray-300 text-gold-600"><span class="text-sm">تتطلب مرفق</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" v-model="form.is_active" class="rounded border-gray-300 text-gold-600"><span class="text-sm">نشط</span></label>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الوصف</label><textarea v-model="form.description" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm resize-none"></textarea></div>
                    <div class="flex gap-3">
                        <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">✅ حفظ</button>
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
                <p class="text-sm text-gray-500 mb-6">حذف نوع الإجازة <strong>{{ deleteTarget.name }}</strong>؟</p>
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

const props = defineProps({ leaveTypes: Array });
const showForm = ref(false);
const isEditing = ref(false);
const editingId = ref(null);
const deleteTarget = ref(null);

const form = useForm({ name: '', code: '', country: 'ALL', default_days: 0, is_paid: true, is_active: true, requires_attachment: false, max_consecutive_days: '', description: '' });

const openModal = () => { isEditing.value = false; editingId.value = null; form.reset(); form.is_paid = true; form.is_active = true; form.country = 'ALL'; form.clearErrors(); showForm.value = true; };

const editItem = (lt) => {
    isEditing.value = true;
    editingId.value = lt.id;
    form.name = lt.name;
    form.code = lt.code;
    form.country = lt.country;
    form.default_days = lt.default_days;
    form.is_paid = !!lt.is_paid;
    form.is_active = !!lt.is_active;
    form.requires_attachment = !!lt.requires_attachment;
    form.max_consecutive_days = lt.max_consecutive_days || '';
    form.description = lt.description || '';
    showForm.value = true;
};

const submit = () => {
    if (isEditing.value) {
        form.put('/leave-types/' + editingId.value, { onSuccess: () => { showForm.value = false; }, preserveScroll: true });
    } else {
        form.post('/leave-types', { onSuccess: () => { showForm.value = false; form.reset(); }, preserveScroll: true, preserveState: false });
    }
};

const confirmDel = () => { router.delete('/leave-types/' + deleteTarget.value.id, { preserveScroll: true, onSuccess: () => { deleteTarget.value = null; } }); };
</script>
