<template>
    <AppLayout>
        <template #header>إدارة الصلاحيات (الأدوار)</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <!-- Controls -->
            <div class="flex justify-end filter-bar">
                <button @click="openModal()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md w-full sm:w-auto">
                    + دور جديد
                </button>
            </div>

            <!-- Roles List -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div v-for="role in roles" :key="role.id" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm relative group">
                    <div class="absolute top-4 left-4 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button v-if="role.slug !== 'admin'" @click="openModal(role)" class="w-8 h-8 flex items-center justify-center rounded-full bg-blue-50 text-blue-600 hover:bg-blue-100 text-sm">✏️</button>
                        <button v-if="role.slug !== 'admin' && role.users_count === 0" @click="delRole(role)" class="w-8 h-8 flex items-center justify-center rounded-full bg-red-50 text-red-600 hover:bg-red-100 text-sm">🗑️</button>
                    </div>
                    
                    <div class="w-12 h-12 rounded-xl bg-gold-50 dark:bg-gold-900/30 text-gold-600 flex items-center justify-center text-2xl mb-4">
                        🛡️
                    </div>
                    <h3 class="font-bold text-lg text-gray-800 dark:text-gray-100 mb-1">{{ role.name }}</h3>
                    <p class="text-sm text-gray-500 font-mono text-left" dir="ltr">@{{ role.slug }}</p>
                    
                    <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex justify-between items-center text-sm">
                        <span class="text-gray-500 dark:text-gray-400">المستخدمين:</span>
                        <span class="font-bold font-mono" :class="role.users_count > 0 ? 'text-green-500' : 'text-gray-400'">{{ role.users_count }}</span>
                    </div>
                </div>
            </div>

            <!-- Modal for Create/Edit -->
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showModal=false">
                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-4xl mx-4 max-h-[90vh] flex flex-col modal-responsive">
                    <div class="flex items-center justify-between p-6 border-b border-gray-100 dark:border-gray-800">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ form.id ? 'تعديل الصلاحية' : 'إضافة صلاحية جديدة' }}</h3>
                        <button @click="showModal=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button>
                    </div>
                    
                    <div class="p-6 overflow-y-auto flex-1">
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اسم الدور (عربي) *</label>
                            <input v-model="form.name" type="text" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white" required placeholder="مثال: محاسب"/>
                            <div v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</div>
                        </div>

                        <h4 class="font-bold text-gray-800 dark:text-gray-200 mb-4 pb-2 border-b border-gray-100 dark:border-gray-800">الصلاحيات</h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div v-for="(perms, moduleName) in permissions" :key="moduleName" class="bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
                                <div class="flex items-center justify-between mb-3 border-b border-gray-200 dark:border-gray-600 pb-2">
                                    <h5 class="font-bold text-sm text-gold-600 dark:text-gold-400">{{ translateModule(moduleName) }}</h5>
                                    <button @click="toggleModule(perms)" type="button" class="text-xs text-blue-500 hover:underline">تحديد الكل</button>
                                </div>
                                <div class="space-y-2">
                                    <label v-for="p in perms" :key="p.id" class="flex items-center gap-2 cursor-pointer text-sm" :title="getPermDescription(p.slug, moduleName)">
                                        <input type="checkbox" :value="p.id" v-model="form.permissions" class="rounded text-gold-500 focus:ring-gold-500 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600"/>
                                        <span class="text-gray-700 dark:text-gray-300">{{ translateAction(p.slug) }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-6 border-t border-gray-100 dark:border-gray-800 flex gap-3">
                        <button @click="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
                            {{ form.id ? 'تحديث الدور' : 'حفظ الدور' }}
                        </button>
                        <button @click="showModal=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 border border-transparent dark:border-gray-700">إلغاء</button>
                    </div>
                </div>
            </div>
            
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
    roles: Array,
    permissions: Object,
});

const moduleTranslations = {
    agents: 'الوكلاء',
    clients: 'العملاء',
    services: 'الخدمات',
    disbursements: 'سندات الصرف',
    receipts: 'سندات القبض',
    invoices: 'الفواتير',
    reports: 'التقارير',
    settings: 'الإعدادات',
    users: 'الموظفين (الإدارة العامة)',
    employees: 'الموظفين (HR)',
    attendance: 'الحضور والانصراف',
    leaves: 'الإجازات',
    advances: 'السلف',
    payroll: 'مسيرات الرواتب',
    hr_reports: 'تقارير الموارد البشرية'
};

const actionTranslations = {
    view: 'عرض',
    create: 'إنشاء / إضافة',
    update: 'تعديل',
    delete: 'حذف',
    approve: 'اعتماد',
    reject: 'رفض',
    submit: 'إرسال للاعتماد',
    manual_edit: 'تعديل يدوي',
    generate: 'توليد'
};

const translateModule = (mod) => moduleTranslations[mod] || mod;
const translateAction = (slug) => {
    const action = slug.split('.')[1];
    return actionTranslations[action] || action;
};
const getPermDescription = (slug, mod) => {
    const action = slug.split('.')[1];
    const actAr = actionTranslations[action] || action;
    const modAr = moduleTranslations[mod] || mod;
    return `يسمح للمستخدم بـ ${actAr} ضمن قسم ${modAr}`;
};

const showModal = ref(false);
const form = useForm({
    id: null,
    name: '',
    permissions: []
});

const openModal = async (role = null) => {
    form.clearErrors();
    if (role) {
        form.id = role.id;
        form.name = role.name;
        try {
            const res = await fetch(`/roles/${role.id}/edit`);
            const data = await res.json();
            form.permissions = data.permission_ids || [];
        } catch (e) {
            form.permissions = [];
        }
    } else {
        form.id = null;
        form.name = '';
        form.permissions = [];
    }
    showModal.value = true;
};

const toggleModule = (modulePermissions) => {
    const ids = modulePermissions.map(p => p.id);
    const allSelected = ids.every(id => form.permissions.includes(id));
    
    if (allSelected) {
        // Remove all
        form.permissions = form.permissions.filter(id => !ids.includes(id));
    } else {
        // Add all
        ids.forEach(id => {
            if (!form.permissions.includes(id)) form.permissions.push(id);
        });
    }
};

const submit = () => {
    if (form.id) {
        form.put(`/roles/${form.id}`, {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => { showModal.value = false; form.reset(); form.clearErrors(); }
        });
    } else {
        form.post('/roles', {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => { showModal.value = false; form.reset(); form.clearErrors(); }
        });
    }
};

const delRole = (role) => {
    if (confirm(`هل أنت متأكد من حذف الصلاحية: ${role.name}؟`)) {
        router.delete(`/roles/${role.id}`, { preserveScroll: true });
    }
};
</script>
