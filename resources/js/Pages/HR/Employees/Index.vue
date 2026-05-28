<template>
    <AppLayout>
        <template #header>الموظفين</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <input v-model="search" type="text" placeholder="بحث بالاسم، الرقم، الهوية..." class="w-64 px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                    
                    <select v-model="departmentFilter" @change="debounceSearch" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500">
                        <option value="">كل الأقسام</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>

                    <select v-model="countryFilter" @change="debounceSearch" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500">
                        <option value="">كل الفروع</option>
                        <option value="SA">السعودية</option>
                        <option value="JO">الأردن</option>
                    </select>
                </div>
                <Link href="/employees/create" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md hover:from-gold-400 hover:to-gold-300 transition-colors">+ موظف جديد</Link>
            </div>
            
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700"><table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600">
                    <th class="px-5 py-3 text-right font-bold">الموظف</th>
                    <th class="px-5 py-3 text-right font-bold">الرقم الوظيفي</th>
                    <th class="px-5 py-3 text-right font-bold">القسم / المسمى</th>
                    <th class="px-5 py-3 text-right font-bold">الوردية</th>
                    <th class="px-5 py-3 text-right font-bold">الفرع</th>
                    <th class="px-5 py-3 text-right font-bold">الحالة</th>
                    <th class="px-5 py-3 text-center font-bold">إجراءات</th>
                </tr></thead>
                <tbody>
                    <tr v-for="e in employees.data" :key="e.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td class="px-5 py-3">
                            <div class="font-bold text-gray-800 dark:text-gray-100">{{ e.user?.name }}</div>
                            <div class="text-xs text-gray-500 mt-0.5">{{ e.user?.email }}</div>
                        </td>
                        <td class="px-5 py-3 font-mono text-xs text-gold-700">{{ e.employee_number }}</td>
                        <td class="px-5 py-3">
                            <div class="font-medium text-gray-800 dark:text-gray-200">{{ e.department?.name || 'بدون قسم' }}</div>
                            <div class="text-[10px] text-gray-500 mt-0.5">{{ e.job_title || 'بدون مسمى' }}</div>
                        </td>
                        <td class="px-5 py-3 text-gray-600">{{ e.shift?.name || 'غير محدد' }}</td>
                        <td class="px-5 py-3">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold" :class="e.country == 'SA' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'">
                                {{ e.country == 'SA' ? 'SA' : 'JO' }}
                            </span>
                        </td>
                        <td class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="e.is_active?'bg-green-100 text-green-700':'bg-red-100 text-red-700'">{{ e.is_active?'نشط':'موقوف' }}</span></td>
                        <td class="px-5 py-3 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <Link :href="'/employees/'+e.id" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg" title="عرض الملف">👁️</Link>
                                <Link :href="'/employees/'+e.id+'/edit'" class="px-2 py-1 text-xs text-gold-700 hover:bg-gold-50 rounded-lg" title="تعديل">✏️</Link>
                                <button v-if="can('employees.delete')" @click="del(e)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg" title="حذف">🗑️</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!employees.data?.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا يوجد موظفين</td></tr>
                </tbody>
            </table></div>
            
            <div v-if="employees.links?.length > 3" class="flex justify-center gap-1 mt-4">
                <Link v-for="(link, i) in employees.links" :key="i" :href="link.url||'#'" v-html="link.label" class="px-3 py-1 rounded border text-sm" :class="link.active?'bg-gold-500 text-white border-gold-500':(!link.url?'opacity-50':'bg-white hover:bg-gray-50')"></Link>
            </div>
        </div>

        <!-- Delete Modal -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">تأكيد الحذف</h3>
                <p class="text-sm text-gray-500 mb-6">حذف الموظف <strong class="text-gray-800 dark:text-gray-100">{{ deleteTarget.user?.name }}</strong>؟</p>
                <div class="flex gap-3 justify-center">
                    <button @click="router.delete('/employees/'+deleteTarget.id,{preserveScroll:true,onSuccess:()=>{deleteTarget=null}})" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600">🗑️ حذف</button>
                    <button @click="deleteTarget=null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import { usePermissions } from '@/composables/usePermissions';

const { can } = usePermissions();
const props = defineProps({ employees: Object, departments: Array, filters: Object });

const search = ref(props.filters?.search||'');
const departmentFilter = ref(props.filters?.department_id||'');
const countryFilter = ref(props.filters?.country||'');
const deleteTarget = ref(null);
let t=null;

const del = (e) => { deleteTarget.value = e; };
const debounceSearch = () => { 
    clearTimeout(t); 
    t = setTimeout(()=>router.get('/employees',{
        search:search.value, 
        department_id:departmentFilter.value,
        country:countryFilter.value
    },{preserveState:true,replace:true}), 400)
};
</script>
