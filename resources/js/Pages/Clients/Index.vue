<template>
    <AppLayout>
        <template #header>العملاء</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <input v-model="search" type="text" placeholder="بحث بالاسم أو الكود أو الهاتف..." class="w-72 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                <button v-if="can('clients.create')" @click="openModal(null)" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md hover:shadow-gold-500/25 w-full sm:w-auto">+ إضافة عميل</button>
            </div>
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm responsive-table">
                    <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                        <th class="px-5 py-3 text-right font-bold">الاسم</th>
                        <th class="px-5 py-3 text-right font-bold hide-mobile">الكود</th>
                        <th class="px-5 py-3 text-right font-bold">الرصيد (الذمة)</th>
                        <th class="px-5 py-3 text-right font-bold">الحالة</th>
                        <th class="px-5 py-3 text-center font-bold">إجراءات</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="c in clients.data" :key="c.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td data-label="الاسم" class="px-5 py-3 text-right font-medium text-gray-800 dark:text-gray-100">{{ c.name }}</td>
                            <td data-label="الكود" class="px-5 py-3 text-right font-mono text-xs text-gold-700 hide-mobile">{{ c.code }}</td>
                            <td data-label="الرصيد" class="px-5 py-3 text-right font-bold font-mono text-xs" :class="parseFloat(c.balance_jod)>=0?'text-green-600':'text-red-600'" dir="ltr">{{ Math.abs(Number(c.balance_jod)).toLocaleString('en',{minimumFractionDigits: c.currency==='SAR'?2:3}) }} {{ c.currency||'JOD' }}</td>
                            <td data-label="الحالة" class="px-5 py-3 text-right"><span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="c.is_active?'bg-green-100 text-green-700':'bg-red-100 text-red-700'">{{ c.is_active?'نشط':'معطل' }}</span></td>
                            <td data-label="" class="px-5 py-3 text-center whitespace-nowrap actions-cell">
                                <a :href="'/clients/'+c.id" class="px-2 py-1 text-xs text-purple-600 hover:bg-purple-50 rounded-lg btn-mobile-sm">📊 كشف</a>
                                <button @click="openView(c)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg btn-mobile-sm">👁️ عرض</button>
                                <button v-if="can('clients.update')" @click="openModal(c)" class="px-2 py-1 text-xs text-gold-700 hover:bg-gold-50 rounded-lg btn-mobile-sm">✏️ تعديل</button>
                                <button v-if="can('clients.delete')" @click="del(c)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️ حذف</button>
                            </td>
                        </tr>
                        <tr v-if="!clients.data?.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا يوجد عملاء</td></tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- View Modal -->
        <div v-if="viewClient" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="viewClient=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6 modal-responsive">
                <div class="flex items-center justify-between mb-4"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">بيانات العميل</h3><button @click="viewClient=null" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <div class="grid grid-cols-2 gap-4 text-sm mobile-form-grid">
                    <div><span class="text-gray-400">الاسم:</span><p class="font-bold">{{ viewClient.name }}</p></div>
                    <div><span class="text-gray-400">الكود:</span><p class="font-mono text-gold-700">{{ viewClient.code }}</p></div>
                    <div><span class="text-gray-400">الدولة:</span><p>{{ {JO:'🇯🇴 الأردن',SA:'🇸🇦 السعودية'}[viewClient.country]||'—' }}</p></div>
                    <div><span class="text-gray-400">المدينة:</span><p>{{ viewClient.city||'—' }}</p></div>
                    <div><span class="text-gray-400">الهاتف:</span><p><span dir="ltr" class="inline-block">{{ viewClient.phone||'—' }}</span></p></div>
                    <div><span class="text-gray-400">البريد:</span><p><span dir="ltr" class="inline-block">{{ viewClient.email||'—' }}</span></p></div>
                    <div><span class="text-gray-400">جهة الاتصال:</span><p>{{ viewClient.contact_person||'—' }}</p></div>
                    <div><span class="text-gray-400">رقم السجل التجاري:</span><p>{{ viewClient.id_number||'—' }}</p></div>
                    <div><span class="text-gray-400">الرصيد:</span><p class="font-bold font-mono" :class="parseFloat(viewClient.balance_jod)>=0?'text-green-600':'text-red-600'">{{ Math.abs(Number(viewClient.balance_jod)).toLocaleString('en',{minimumFractionDigits: viewClient.currency==='SAR'?2:3}) }} {{ viewClient.currency||'JOD' }}</p></div>
                    <div><span class="text-gray-400">الحالة:</span><p><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="viewClient.is_active?'bg-green-100 text-green-700':'bg-red-100 text-red-700'">{{ viewClient.is_active?'نشط':'معطل' }}</span></p></div>
                    <div class="col-span-2"><span class="text-gray-400">العنوان:</span><p>{{ viewClient.address||'—' }}</p></div>
                    <div class="col-span-2"><span class="text-gray-400">ملاحظات:</span><p>{{ viewClient.notes||'—' }}</p></div>
                </div>
            </div>
        </div>

        <!-- Form Modal -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showForm=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto modal-responsive">
                <div class="flex items-center justify-between mb-5"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ editItem?'تعديل العميل':'إضافة عميل جديد' }}</h3><button @click="showForm=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mobile-form-grid">
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">اسم الشركة *</label><input v-model="form.name" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/><p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">جهة الاتصال</label><input v-model="form.contact_person" placeholder="اسم المسؤول" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">الدولة *</label><select v-model="form.country" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500"><option value="">اختر الدولة</option><option value="SA">🇸🇦 السعودية</option><option value="JO">🇯🇴 الأردن</option></select><p class="mt-1 text-xs text-gray-400">العملة: {{ form.country==='JO'?'JOD دينار':'SAR ريال' }}</p></div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">المدينة</label><select v-model="form.city" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500"><option value="">اختر المدينة</option><option v-for="c in citiesList" :key="c" :value="c">{{ c }}</option></select></div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">الهاتف</label>
                            <div class="flex" dir="ltr">
                                <span class="inline-flex items-center px-3 py-2.5 rounded-s-xl border border-e-0 border-gray-200 bg-gray-50 text-sm text-gray-600 font-mono">{{ form.country==='JO'?'+962':'+966' }}</span>
                                <input v-model="form.phone" type="tel" :placeholder="form.country==='JO'?'7XXXXXXXX':'5XXXXXXXX'" :maxlength="9" class="flex-1 px-4 py-2.5 rounded-e-xl border border-gray-200 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <p v-if="phoneError" class="mt-1 text-xs text-red-500">{{ phoneError }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">البريد الإلكتروني</label>
                            <input v-model="form.email" type="email" dir="ltr" placeholder="example@email.com" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            <p v-if="emailError" class="mt-1 text-xs text-red-500">{{ emailError }}</p>
                        </div>
                        <div><label class="block text-sm font-medium text-gray-700 mb-1">رقم السجل التجاري</label><input v-model="form.id_number" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/></div>
                        <div v-if="editItem" class="flex items-end"><label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/><span class="text-sm">نشط</span></label></div>
                    </div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">العنوان</label><textarea v-model="form.address" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea></div>
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label><textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea></div>
                    <div class="flex gap-3 pt-2">
                        <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">{{ editItem?'💾 تحديث':'✅ إضافة' }}</button>
                        <button type="button" @click="showForm=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">تأكيد الحذف</h3>
                <p class="text-sm text-gray-500 mb-6">هل أنت متأكد من حذف العميل <strong class="text-gray-800 dark:text-gray-100">{{ deleteTarget.name }}</strong>؟</p>
                <div class="flex gap-3 justify-center">
                    <button @click="confirmDelete" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600 shadow-md">🗑️ حذف</button>
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
import { usePermissions } from '@/composables/usePermissions';
const { can } = usePermissions();

const saCities = ['الرياض','جدة','مكة المكرمة','المدينة المنورة','الدمام','الخبر','الطائف','تبوك','أبها','القصيم','حائل','نجران','ينبع','الأحساء','الجبيل','القطيف'];
const joCities = ['عمان','إربد','الزرقاء','العقبة','السلط','الكرك','مادبا','جرش','عجلون','معان','الطفيلة','البلقاء'];

const props = defineProps({ clients: Object, filters: Object });
const search = ref(props.filters?.search||'');
const showForm = ref(false); const editItem = ref(null); const viewClient = ref(null);
let t=null;

const form = useForm({ name:'',phone:'',email:'',country:'',city:'',contact_person:'',id_number:'',address:'',notes:'',is_active:true });

const citiesList = computed(() => form.country === 'JO' ? joCities : form.country === 'SA' ? saCities : []);
watch(() => form.country, (nv, ov) => { if (ov && nv !== ov) form.city = ''; });

const phoneError = computed(() => {
    if (!form.phone) return '';
    const d = form.phone.replace(/\D/g, '');
    if (d.length !== 9) return 'يجب أن يتكون الرقم من 9 أرقام';
    if (form.country === 'JO' && !d.startsWith('7')) return 'رقم الأردن يجب أن يبدأ بـ 7';
    if (form.country === 'SA' && !d.startsWith('5')) return 'رقم السعودية يجب أن يبدأ بـ 5';
    return '';
});
const emailError = computed(() => {
    if (!form.email) return '';
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email) ? '' : 'بريد إلكتروني غير صالح';
});

const openModal = (c) => {
    editItem.value = c;
    form.name = c?.name||'';
    form.contact_person = c?.contact_person||'';
    let ph = c?.phone||'';
    ph = ph.replace(/^\+962/, '').replace(/^\+966/, '');
    form.phone = ph;
    form.email = c?.email||'';
    form.country = c?.country||'';
    form.city = c?.city||'';
    form.id_number = c?.id_number||'';
    form.address = c?.address||'';
    form.notes = c?.notes||'';
    form.is_active = c?.is_active??true;
    form.clearErrors();
    showForm.value = true;
};
const openView = (c) => { viewClient.value = c; };
const submit = () => {
    if (phoneError.value || emailError.value) return;
    if (form.phone) {
        const prefix = form.country === 'JO' ? '+962' : '+966';
        const digits = form.phone.replace(/\D/g, '');
        form.phone = prefix + digits;
    }
    const o = { onSuccess:()=>{showForm.value=false; form.reset(); form.clearErrors(); editItem.value=null;}, preserveScroll:true, preserveState:false };
    editItem.value ? form.put('/clients/'+editItem.value.id, o) : form.post('/clients', o);
};
const debounceSearch = () => { clearTimeout(t); t=setTimeout(()=>router.get('/clients',{search:search.value},{preserveState:true,replace:true}),400); };
const deleteTarget = ref(null);
const del = (c) => { deleteTarget.value = c; };
const confirmDelete = () => {
    if (deleteTarget.value) {
        router.delete('/clients/'+deleteTarget.value.id, {
            preserveScroll: true,
            onSuccess: () => { deleteTarget.value = null; },
        });
    }
};
</script>
