<template>
    <AppLayout>
        <template #header>الموظفين</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <!-- Controls -->
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <div class="flex items-center gap-3 flex-wrap">
                    <input v-model="search" type="text" placeholder="بحث بالاسم أو البريد..." class="w-64 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500 dark:text-white" @input="debounceSearch"/>
                    <select v-model="roleFilter" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white" @change="applyFilter">
                        <option value="">كل الصلاحيات</option>
                        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                </div>
                <button @click="openModal()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md hover:shadow-gold-500/25 w-full sm:w-auto">
                    + موظف جديد
                </button>
            </div>

            <!-- Table -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm responsive-table">
                    <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400">
                        <th class="px-4 py-3 text-right font-bold">الاسم</th>
                        <th class="px-4 py-3 text-right font-bold hide-mobile">البريد الإلكتروني</th>
                        <th class="px-4 py-3 text-right font-bold hide-mobile">رقم الهاتف</th>
                        <th class="px-4 py-3 text-right font-bold">الدور (الصلاحية)</th>
                        <th class="px-4 py-3 text-right font-bold">الحالة</th>
                        <th class="px-4 py-3 text-center font-bold">إجراءات</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="user in users.data" :key="user.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30">
                            <td data-label="الاسم" class="px-4 py-3 text-right font-bold text-gray-800 dark:text-gray-200">{{ user.name }}</td>
                            <td data-label="البريد" class="px-4 py-3 text-right text-gray-600 dark:text-gray-400 font-mono text-xs hide-mobile">{{ user.email }}</td>
                            <td data-label="الهاتف" class="px-4 py-3 text-right text-gray-600 dark:text-gray-400 font-mono text-xs hide-mobile" dir="ltr">{{ user.phone || '—' }}</td>
                            <td data-label="الدور" class="px-4 py-3 text-right">
                                <span class="px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">{{ user.role?.name || 'غير محدد' }}</span>
                            </td>
                            <td data-label="الحالة" class="px-4 py-3 text-right">
                                <span v-if="user.is_active" class="px-2 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-700">نشط</span>
                                <span v-else class="px-2 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-700">موقوف</span>
                            </td>
                            <td data-label="" class="px-4 py-3 text-center whitespace-nowrap actions-cell">
                                <button @click="openModal(user)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg mr-1 btn-mobile-sm">✏️</button>
                                <button v-if="$page.props.auth.user.id !== user.id" @click="delUser(user)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️</button>
                            </td>
                        </tr>
                        <tr v-if="!users.data?.length"><td colspan="6" class="px-5 py-12 text-center text-gray-400">لا يوجد موظفين</td></tr>
                    </tbody>
                </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <div v-if="users.links?.length > 3" class="flex justify-center gap-1 mt-4">
                <template v-for="(link, i) in users.links" :key="i">
                    <button v-if="link.url" @click="router.get(link.url, {search, role_id: roleFilter}, {preserveState:true})" v-html="link.label" class="px-3 py-1 rounded text-sm" :class="link.active ? 'bg-gold-500 text-black font-bold' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700'"></button>
                    <span v-else v-html="link.label" class="px-3 py-1 text-gray-400 text-sm"></span>
                </template>
            </div>
        </div>

        <!-- Create / Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showModal=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6 modal-responsive">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ form.id ? 'تعديل موظف' : 'موظف جديد' }}</h3>
                    <button @click="showModal=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button>
                </div>
                
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الاسم *</label>
                        <input v-model="form.name" type="text" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-gold-500 dark:text-white"/>
                        <div v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">البريد الإلكتروني *</label>
                        <input v-model="form.email" type="email" required dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-gold-500 dark:text-white"/>
                        <div v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الدور (الصلاحية) *</label>
                        <select v-model="form.role_id" required class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-gold-500 dark:text-white">
                            <option value="" disabled>اختر الدور</option>
                            <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                        <div v-if="form.errors.role_id" class="text-red-500 text-xs mt-1">{{ form.errors.role_id }}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">كلمة المرور <span v-if="!form.id">*</span><span v-else class="text-gray-400 font-normal">(اتركها فارغة إذا لم ترد تغييرها)</span></label>
                        <input v-model="form.password" type="password" :required="!form.id" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-gold-500 dark:text-white"/>
                        <div v-if="form.errors.password" class="text-red-500 text-xs mt-1">{{ form.errors.password }}</div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">رقم الهاتف</label>
                        <input v-model="form.phone" type="text" dir="ltr" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-gold-500 dark:text-white"/>
                        <div v-if="form.errors.phone" class="text-red-500 text-xs mt-1">{{ form.errors.phone }}</div>
                    </div>
                    <div class="flex items-center gap-2 mt-2">
                        <input type="checkbox" v-model="form.is_active" id="isActive" class="rounded text-gold-500 focus:ring-gold-500"/>
                        <label for="isActive" class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer">حساب نشط (يمكنه تسجيل الدخول)</label>
                    </div>

                    <div class="flex gap-3 pt-4 border-t border-gray-100 dark:border-gray-800">
                        <button type="submit" :disabled="form.processing" class="flex-1 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
                            {{ form.id ? 'تحديث' : 'إضافة' }}
                        </button>
                        <button type="button" @click="showModal=false" class="flex-1 py-2.5 rounded-xl text-sm text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ users: Object, roles: Array, filters: Object });
const search = ref(props.filters?.search || '');
const roleFilter = ref(props.filters?.role_id || '');
const showModal = ref(false);
let t = null;

const form = useForm({
    id: null,
    name: '',
    email: '',
    password: '',
    role_id: '',
    phone: '',
    is_active: true
});

const openModal = (user = null) => {
    form.clearErrors();
    if (user) {
        form.id = user.id;
        form.name = user.name;
        form.email = user.email;
        form.role_id = user.role_id;
        form.phone = user.phone || '';
        form.is_active = user.is_active;
        form.password = '';
    } else {
        form.reset();
        form.id = null;
        form.is_active = true;
    }
    showModal.value = true;
};

const submit = () => {
    if (form.id) {
        form.put(`/users/${form.id}`, {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => { showModal.value = false; form.reset(); form.clearErrors(); }
        });
    } else {
        form.post('/users', {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => { showModal.value = false; form.reset(); form.clearErrors(); }
        });
    }
};

const delUser = (user) => {
    if (confirm(`هل أنت متأكد من حذف الموظف: ${user.name}؟`)) {
        router.delete(`/users/${user.id}`, { preserveScroll: true });
    }
};

const debounceSearch = () => { clearTimeout(t); t = setTimeout(() => applyFilter(), 400); };
const applyFilter = () => { router.get('/users', { search: search.value, role_id: roleFilter.value }, { preserveState: true, replace: true }); };
</script>
