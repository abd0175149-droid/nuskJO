<template>
    <AppLayout>
        <template #header>تصنيفات المصاريف</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            <div class="flex justify-end filter-bar"><button @click="openModal(null)" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md w-full sm:w-auto">+ تصنيف جديد</button></div>
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700"><table class="w-full text-sm responsive-table">
                <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400"><th class="px-5 py-3 text-right font-bold">الاسم</th><th class="px-5 py-3 text-right font-bold hide-mobile">الكود</th><th class="px-5 py-3 text-right font-bold hide-mobile">الحساب المحاسبي</th><th class="px-5 py-3 text-right font-bold">الحالة</th><th class="px-5 py-3 text-center font-bold">إجراءات</th></tr></thead>
                <tbody>
                    <tr v-for="c in categories" :key="c.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td data-label="الاسم" class="px-5 py-3 font-medium text-gray-800 dark:text-gray-100">{{ c.name }}</td>
                        <td data-label="الكود" class="px-5 py-3 font-mono text-xs text-gold-700 hide-mobile">{{ c.code }}</td>
                        <td data-label="الحساب" class="px-5 py-3 text-sm hide-mobile">
                            <span v-if="c.account" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 text-xs font-bold">
                                <span class="font-mono">{{ c.account.code }}</span>
                                <span class="text-blue-500">—</span>
                                {{ c.account.name }}
                            </span>
                            <span v-else class="text-xs text-gray-400">غير مربوط ⚠️</span>
                        </td>
                        <td data-label="الحالة" class="px-5 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="c.is_active?'bg-green-100 text-green-700':'bg-red-100 text-red-700'">{{ c.is_active?'نشط':'معطل' }}</span></td>
                        <td data-label="" class="px-5 py-3 text-center actions-cell"><button @click="openModal(c)" class="px-2 py-1 text-xs text-gold-700 hover:bg-gold-50 rounded-lg btn-mobile-sm">✏️</button><button @click="del(c)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️</button></td>
                    </tr>
                    <tr v-if="!categories.length"><td colspan="5" class="px-5 py-12 text-center text-gray-400">لا يوجد تصنيفات</td></tr>
                </tbody>
            </table></div>
        </div>

        <!-- Modal إنشاء/تعديل -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6 modal-responsive">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ editItem?'تعديل':'إضافة' }} تصنيف</h3><button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">الاسم *</label><input v-model="form.name" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>

                    <!-- حقل ربط الحساب المحاسبي -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">الحساب المحاسبي</label>
                        <div class="relative">
                            <select v-model="form.account_id" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none appearance-none bg-white">
                                <option :value="null">— بدون ربط (افتراضي: مصاريف تشغيلية 5100) —</option>
                                <option v-for="acc in expenseAccounts" :key="acc.id" :value="acc.id">{{ acc.code }} — {{ acc.name }}</option>
                            </select>
                            <div class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">▾</div>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">اختر الحساب المحاسبي الذي يُسجل عليه هذا التصنيف في دفتر اليومية</p>
                    </div>

                    <div><label class="block text-sm font-medium text-gray-700 mb-1">الوصف</label><textarea v-model="form.description" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea></div>
                    <div v-if="editItem"><label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/><span class="text-sm">نشط</span></label></div>
                    <div class="flex gap-3"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">{{ editItem?'💾 تحديث':'✅ إضافة' }}</button><button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button></div>
                </form>
            </div>
        </div>

        <!-- Modal تأكيد الحذف -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div><p class="text-sm text-gray-500 mb-6">حذف <strong>{{ deleteTarget.name }}</strong>؟</p>
                <div class="flex gap-3 justify-center"><button @click="router.delete('/expense-categories/'+deleteTarget.id,{preserveScroll:true,onSuccess:()=>{deleteTarget=null}})" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500">🗑️ حذف</button><button @click="deleteTarget=null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button></div>
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
const props = defineProps({ categories: Array, expenseAccounts: Array });
const showForm = ref(false); const editItem = ref(null); const deleteTarget = ref(null);
const form = useForm({ name:'', description:'', is_active:true, account_id: null });
const openModal = (c) => {
    editItem.value = c;
    form.name = c?.name || '';
    form.description = c?.description || '';
    form.is_active = c?.is_active ?? true;
    form.account_id = c?.account_id ?? null;
    form.clearErrors();
    showForm.value = true;
};
const submit = () => {
    const o = {
        onSuccess: () => { showForm.value=false; form.reset(); form.clearErrors(); editItem.value=null; },
        preserveScroll: true,
        preserveState: false,
    };
    editItem.value ? form.put('/expense-categories/'+editItem.value.id, o) : form.post('/expense-categories', o);
};
const del = (c) => { deleteTarget.value=c; };
</script>
