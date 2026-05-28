<template>
    <AppLayout>
        <template #header>أنواع المخالفات</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <input v-model="search" type="text" placeholder="بحث..." class="w-64 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                <button @click="openModal(null)" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md w-full sm:w-auto">+ نوع مخالفة</button>
            </div>
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700"><table class="w-full text-sm responsive-table">
                <thead><tr class="bg-gray-50 text-gray-600">
                    <th class="px-5 py-3 text-right font-bold">الاسم</th>
                    <th class="px-5 py-3 text-right font-bold hide-mobile">الكود</th>
                    <th class="px-5 py-3 text-right font-bold">التكلفة (SAR)</th>
                    <th class="px-5 py-3 text-right font-bold">الحالة</th>
                    <th class="px-5 py-3 text-center font-bold">إجراءات</th>
                </tr></thead>
                <tbody>
                    <tr v-for="vt in types.data" :key="vt.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td data-label="الاسم" class="px-5 py-3 font-medium text-gray-800 dark:text-gray-100">{{ vt.name }}</td>
                        <td data-label="الكود" class="px-5 py-3 font-mono text-xs text-gold-700 hide-mobile">{{ vt.code }}</td>
                        <td data-label="التكلفة" class="px-5 py-3 font-mono" dir="ltr">{{ Number(vt.default_cost_sar).toLocaleString('en',{minimumFractionDigits:2}) }}</td>
                        <td data-label="الحالة" class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="vt.is_active?'bg-green-100 text-green-700':'bg-red-100 text-red-700'">{{ vt.is_active?'نشط':'معطل' }}</span></td>
                        <td data-label="" class="px-5 py-3 text-center actions-cell">
                            <button @click="openModal(vt)" class="px-2 py-1 text-xs text-gold-700 hover:bg-gold-50 rounded-lg btn-mobile-sm">✏️</button>
                            <button @click="del(vt)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️</button>
                        </td>
                    </tr>
                    <tr v-if="!types.data?.length"><td colspan="5" class="px-5 py-12 text-center text-gray-400">لا يوجد أنواع مخالفات</td></tr>
                </tbody>
            </table></div>
        </div>
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6 modal-responsive">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ editItem?'تعديل نوع المخالفة':'نوع مخالفة جديد' }}</h3><button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">اسم المخالفة *</label><input v-model="form.name" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">التكلفة الافتراضية (SAR) *</label><input v-model="form.default_cost_sar" type="number" step="0.01" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">الوصف</label><textarea v-model="form.description" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea></div>
                    <div v-if="editItem"><label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/><span class="text-sm">نشط</span></label></div>
                    <div class="flex gap-3"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">{{ editItem?'💾 تحديث':'✅ إضافة' }}</button><button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button></div>
                </form>
            </div>
        </div>
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">تأكيد الحذف</h3>
                <p class="text-sm text-gray-500 mb-6">حذف نوع المخالفة <strong class="text-gray-800 dark:text-gray-100">{{ deleteTarget.name }}</strong>؟</p>
                <div class="flex gap-3 justify-center">
                    <button @click="router.delete('/violation-types/'+deleteTarget.id,{preserveScroll:true,onSuccess:()=>{deleteTarget=null}})" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600">🗑️ حذف</button>
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
const props = defineProps({ types: Object, filters: Object });
const search = ref(props.filters?.search||'');
const showForm = ref(false); const editItem = ref(null); const deleteTarget = ref(null);
let t=null;
const form = useForm({ name:'',default_cost_sar:'',description:'',is_active:true });
const openModal=(v)=>{ editItem.value=v; form.name=v?.name||''; form.default_cost_sar=v?.default_cost_sar||''; form.description=v?.description||''; form.is_active=v?.is_active??true; form.clearErrors(); showForm.value=true; };
const submit=()=>{ const o={onSuccess:()=>{showForm.value=false; form.reset(); form.clearErrors(); editItem.value=null;},preserveScroll:true,preserveState:false}; editItem.value?form.put('/violation-types/'+editItem.value.id,o):form.post('/violation-types',o); };
const del=(v)=>{ deleteTarget.value=v; };
const debounceSearch=()=>{clearTimeout(t);t=setTimeout(()=>router.get('/violation-types',{search:search.value},{preserveState:true,replace:true}),400)};
</script>
