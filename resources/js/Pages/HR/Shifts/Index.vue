<template>
    <AppLayout>
        <template #header>ورديات العمل</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex gap-2">
                    <input v-model="search" type="text" placeholder="بحث..." class="w-64 px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                    <select v-model="countryFilter" @change="debounceSearch" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500">
                        <option value="">كل الفروع</option>
                        <option value="SA">السعودية</option>
                        <option value="JO">الأردن</option>
                        <option value="ALL">مُشترك</option>
                    </select>
                </div>
                <button @click="openModal(null)" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ وردية جديدة</button>
            </div>
            
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700"><table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-600">
                    <th class="px-5 py-3 text-right font-bold">اسم الوردية</th>
                    <th class="px-5 py-3 text-right font-bold">الكود / الفرع</th>
                    <th class="px-5 py-3 text-right font-bold">النوع</th>
                    <th class="px-5 py-3 text-right font-bold">الدوام</th>
                    <th class="px-5 py-3 text-center font-bold">الموظفين</th>
                    <th class="px-5 py-3 text-right font-bold">الحالة</th>
                    <th class="px-5 py-3 text-center font-bold">إجراءات</th>
                </tr></thead>
                <tbody>
                    <tr v-for="s in shifts.data" :key="s.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                        <td class="px-5 py-3 font-medium text-gray-800 dark:text-gray-100">{{ s.name }}</td>
                        <td class="px-5 py-3">
                            <div class="font-mono text-xs text-gold-700">{{ s.code }}</div>
                            <div class="text-[10px] text-gray-500 mt-0.5">{{ s.country == 'SA' ? 'السعودية' : (s.country == 'JO' ? 'الأردن' : 'مُشترك') }}</div>
                        </td>
                        <td class="px-5 py-3">
                            <span v-if="s.is_flexible" class="px-2 py-0.5 bg-blue-50 text-blue-600 rounded text-xs font-bold">مرنة ({{ s.min_hours }} ساعات)</span>
                            <span v-else class="px-2 py-0.5 bg-purple-50 text-purple-600 rounded text-xs font-bold">ثابتة</span>
                        </td>
                        <td class="px-5 py-3 text-gray-600" dir="ltr">
                            <template v-if="!s.is_flexible">
                                {{ formatTime(s.start_time) }} - {{ formatTime(s.end_time) }}
                            </template>
                            <template v-else>
                                مرن
                            </template>
                        </td>
                        <td class="px-5 py-3 text-center">{{ s.employees_count || 0 }}</td>
                        <td class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="s.is_active?'bg-green-100 text-green-700':'bg-red-100 text-red-700'">{{ s.is_active?'نشط':'معطل' }}</span></td>
                        <td class="px-5 py-3 text-center">
                            <button @click="openModal(s)" class="px-2 py-1 text-xs text-gold-700 hover:bg-gold-50 rounded-lg">✏️</button>
                            <button v-if="can('employees.delete')" @click="del(s)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg">🗑️</button>
                        </td>
                    </tr>
                    <tr v-if="!shifts.data?.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا يوجد ورديات</td></tr>
                </tbody>
            </table></div>
            
            <div v-if="shifts.links?.length > 3" class="flex justify-center gap-1 mt-4">
                <Link v-for="(link, i) in shifts.links" :key="i" :href="link.url||'#'" v-html="link.label" class="px-3 py-1 rounded border text-sm" :class="link.active?'bg-gold-500 text-white border-gold-500':(!link.url?'opacity-50':'bg-white hover:bg-gray-50')"></Link>
            </div>
        </div>

        <!-- Form Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 overflow-y-auto py-10" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl mx-4 p-6">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ editItem?'تعديل الوردية':'وردية جديدة' }}</h3><button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">اسم الوردية *</label>
                            <input v-model="form.name" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">الكود *</label>
                            <input v-model="form.code" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            <p v-if="form.errors.code" class="text-red-500 text-xs mt-1">{{ form.errors.code }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">الفرع *</label>
                            <select v-model="form.country" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none">
                                <option value="ALL">مُشترك للجميع</option>
                                <option value="SA">السعودية فقط</option>
                                <option value="JO">الأردن فقط</option>
                            </select>
                            <p v-if="form.errors.country" class="text-red-500 text-xs mt-1">{{ form.errors.country }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">مدة الاستراحة (دقائق)</label>
                            <input v-model.number="form.break_minutes" type="number" min="0" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                        </div>
                    </div>

                    <div class="p-4 border rounded-xl bg-gray-50">
                        <label class="flex items-center gap-2 mb-3"><input v-model="form.is_flexible" type="checkbox" class="w-4 h-4 rounded text-gold-500"/><span class="font-bold text-gray-800">وردية مرنة (Flexible)</span></label>
                        <p class="text-xs text-gray-500 mb-4">في الوردية المرنة الموظف غير ملزم بوقت دخول محدد، بل يلتزم بإكمال عدد الساعات المطلوبة يومياً.</p>
                        
                        <div v-if="form.is_flexible">
                            <label class="block text-sm font-medium text-gray-700 mb-1">الحد الأدنى لساعات العمل اليومية *</label>
                            <input v-model.number="form.min_hours" type="number" step="0.5" min="1" :required="form.is_flexible" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                        </div>
                        
                        <div v-else class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">وقت الحضور *</label>
                                <input v-model="form.start_time" type="time" :required="!form.is_flexible" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">وقت الانصراف *</label>
                                <input v-model="form.end_time" type="time" :required="!form.is_flexible" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">فترة السماح التأخير (دقائق)</label>
                                <input v-model.number="form.grace_minutes" type="number" min="0" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">أيام العمل الأسبوعية *</label>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="(day, index) in daysList" :key="index" class="flex items-center gap-2 p-2 border rounded-lg cursor-pointer hover:bg-gray-50" :class="form.working_days.includes(index) ? 'bg-gold-50 border-gold-300' : ''">
                                <input type="checkbox" :value="index" v-model="form.working_days" class="w-4 h-4 rounded text-gold-500"/>
                                <span class="text-sm font-medium text-gray-700">{{ day }}</span>
                            </label>
                        </div>
                        <p v-if="form.errors.working_days" class="text-red-500 text-xs mt-1">{{ form.errors.working_days }}</p>
                    </div>

                    <div v-if="editItem">
                        <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/><span class="text-sm">نشط</span></label>
                    </div>
                    
                    <div class="flex gap-3 pt-4 border-t"><button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">{{ editItem?'💾 تحديث':'✅ إضافة' }}</button><button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button></div>
                </form>
            </div>
        </div>
        
        <!-- Delete Modal -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">تأكيد الحذف</h3>
                <p class="text-sm text-gray-500 mb-6">حذف الوردية <strong class="text-gray-800 dark:text-gray-100">{{ deleteTarget.name }}</strong>؟</p>
                <div class="flex gap-3 justify-center">
                    <button @click="router.delete('/shifts/'+deleteTarget.id,{preserveScroll:true,onSuccess:()=>{deleteTarget=null}})" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600">🗑️ حذف</button>
                    <button @click="deleteTarget=null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import { usePermissions } from '@/composables/usePermissions';

const { can } = usePermissions();
const props = defineProps({ shifts: Object, filters: Object });

const search = ref(props.filters?.search||'');
const countryFilter = ref(props.filters?.country||'');
const showForm = ref(false); 
const editItem = ref(null); 
const deleteTarget = ref(null);
let t=null;

const daysList = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

const form = useForm({ 
    name:'', 
    code:'', 
    start_time: '', 
    end_time: '',
    grace_minutes: 15,
    working_days: [0,1,2,3,4],
    country: 'ALL',
    break_minutes: 60,
    is_flexible: false,
    min_hours: 8,
    is_active: true 
});

const formatTime = (timeString) => {
    if (!timeString) return '';
    return timeString.substring(0, 5); // Returns HH:MM from HH:MM:SS
};

const openModal = (s) => { 
    editItem.value = s; 
    if (s) {
        form.name = s.name;
        form.code = s.code;
        form.start_time = formatTime(s.start_time);
        form.end_time = formatTime(s.end_time);
        form.grace_minutes = s.grace_minutes;
        form.working_days = s.working_days || [];
        form.country = s.country;
        form.break_minutes = s.break_minutes;
        form.is_flexible = s.is_flexible;
        form.min_hours = s.min_hours;
        form.is_active = s.is_active;
    } else {
        form.reset();
    }
    form.clearErrors(); 
    showForm.value = true; 
};

const submit = () => { 
    const o = {
        onSuccess: () => {
            showForm.value=false; 
            form.reset(); 
            form.clearErrors(); 
            editItem.value=null;
        },
        preserveScroll:true,
        preserveState:false
    }; 
    
    // If flexible, clear start/end times
    if (form.is_flexible) {
        form.start_time = null;
        form.end_time = null;
    } else {
        form.min_hours = null;
    }
    
    editItem.value ? form.put('/shifts/'+editItem.value.id, o) : form.post('/shifts', o); 
};

const del = (s) => { deleteTarget.value = s; };
const debounceSearch = () => { 
    clearTimeout(t); 
    t = setTimeout(()=>router.get('/shifts',{search:search.value, country:countryFilter.value},{preserveState:true,replace:true}), 400)
};
</script>
