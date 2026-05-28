<template>
    <component :is="layoutComponent">
        <div class="max-w-2xl mx-auto">
            <!-- Header -->
            <div class="mb-6">
                <h1 class="text-xl sm:text-2xl font-bold" :class="isDark ? 'text-white' : 'text-gray-800'">
                    ⚙️ إعدادات الحساب
                </h1>
                <p class="text-sm mt-1" :class="isDark ? 'text-gray-400' : 'text-gray-500'">إدارة بياناتك الشخصية وكلمة المرور</p>
            </div>

            <!-- Flash Messages -->
            <div v-if="$page.props.flash?.success" class="mb-4 p-3 rounded-xl border text-sm font-bold"
                 :class="isDark ? 'bg-green-900/20 border-green-800 text-green-400' : 'bg-green-50 border-green-200 text-green-700'">
                ✅ {{ $page.props.flash.success }}
            </div>

            <!-- Personal Info Card -->
            <div class="rounded-2xl border shadow-sm p-6 mb-6"
                 :class="isDark ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'">
                <h2 class="text-lg font-bold mb-4 flex items-center gap-2"
                    :class="isDark ? 'text-gold-400' : 'text-gold-700'">
                    <span>👤</span> البيانات الشخصية
                </h2>

                <form @submit.prevent="saveInfo" class="space-y-4">
                    <!-- Name -->
                    <div>
                        <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">الاسم</label>
                        <input v-model="infoForm.name" type="text" required
                               class="w-full px-4 py-2.5 rounded-xl border text-sm transition-colors focus:ring-2 focus:ring-gold-400 focus:border-gold-400 outline-none"
                               :class="isDark ? 'bg-gray-800 border-gray-700 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'"/>
                        <p v-if="infoForm.errors.name" class="text-red-500 text-xs mt-1">{{ infoForm.errors.name }}</p>
                    </div>

                    <!-- Email -->
                    <div>
                        <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">البريد الإلكتروني</label>
                        <input v-model="infoForm.email" type="email" required dir="ltr"
                               class="w-full px-4 py-2.5 rounded-xl border text-sm transition-colors focus:ring-2 focus:ring-gold-400 focus:border-gold-400 outline-none"
                               :class="isDark ? 'bg-gray-800 border-gray-700 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'"/>
                        <p v-if="infoForm.errors.email" class="text-red-500 text-xs mt-1">{{ infoForm.errors.email }}</p>
                    </div>

                    <!-- Phone -->
                    <div>
                        <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">الهاتف</label>
                        <input v-model="infoForm.phone" type="tel" dir="ltr"
                               class="w-full px-4 py-2.5 rounded-xl border text-sm transition-colors focus:ring-2 focus:ring-gold-400 focus:border-gold-400 outline-none"
                               :class="isDark ? 'bg-gray-800 border-gray-700 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'"
                               placeholder="0771234567"/>
                        <p v-if="infoForm.errors.phone" class="text-red-500 text-xs mt-1">{{ infoForm.errors.phone }}</p>
                    </div>

                    <!-- Role (Read-only) -->
                    <div>
                        <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">الدور</label>
                        <div class="px-4 py-2.5 rounded-xl border text-sm"
                             :class="isDark ? 'bg-gray-800/50 border-gray-700 text-gray-400' : 'bg-gray-100 border-gray-200 text-gray-500'">
                            {{ user?.role?.name || (page.props.auth?.isAdmin ? 'مدير النظام' : 'موظف') }}
                        </div>
                    </div>

                    <button type="submit" :disabled="infoForm.processing"
                            class="w-full py-2.5 rounded-xl text-sm font-bold text-white transition-all duration-200"
                            :class="infoForm.processing ? 'bg-gold-400 cursor-wait' : 'bg-gold-600 hover:bg-gold-700 active:scale-[0.98]'">
                        {{ infoForm.processing ? 'جاري الحفظ...' : '💾 حفظ التغييرات' }}
                    </button>
                </form>
            </div>

            <!-- Password Card -->
            <div class="rounded-2xl border shadow-sm p-6"
                 :class="isDark ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'">
                <h2 class="text-lg font-bold mb-4 flex items-center gap-2"
                    :class="isDark ? 'text-gold-400' : 'text-gold-700'">
                    <span>🔒</span> تغيير كلمة المرور
                </h2>

                <form @submit.prevent="savePassword" class="space-y-4">
                    <!-- Current Password -->
                    <div>
                        <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">كلمة المرور الحالية</label>
                        <input v-model="passForm.current_password" type="password" required
                               class="w-full px-4 py-2.5 rounded-xl border text-sm transition-colors focus:ring-2 focus:ring-gold-400 focus:border-gold-400 outline-none"
                               :class="isDark ? 'bg-gray-800 border-gray-700 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'"/>
                        <p v-if="passForm.errors.current_password" class="text-red-500 text-xs mt-1">{{ passForm.errors.current_password }}</p>
                    </div>

                    <!-- New Password -->
                    <div>
                        <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">كلمة المرور الجديدة</label>
                        <input v-model="passForm.password" type="password" required minlength="6"
                               class="w-full px-4 py-2.5 rounded-xl border text-sm transition-colors focus:ring-2 focus:ring-gold-400 focus:border-gold-400 outline-none"
                               :class="isDark ? 'bg-gray-800 border-gray-700 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'"/>
                        <p v-if="passForm.errors.password" class="text-red-500 text-xs mt-1">{{ passForm.errors.password }}</p>
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <label class="block text-sm font-medium mb-1.5" :class="isDark ? 'text-gray-300' : 'text-gray-700'">تأكيد كلمة المرور</label>
                        <input v-model="passForm.password_confirmation" type="password" required minlength="6"
                               class="w-full px-4 py-2.5 rounded-xl border text-sm transition-colors focus:ring-2 focus:ring-gold-400 focus:border-gold-400 outline-none"
                               :class="isDark ? 'bg-gray-800 border-gray-700 text-white' : 'bg-gray-50 border-gray-200 text-gray-900'"/>
                    </div>

                    <button type="submit" :disabled="passForm.processing"
                            class="w-full py-2.5 rounded-xl text-sm font-bold text-white transition-all duration-200"
                            :class="passForm.processing ? 'bg-gold-400 cursor-wait' : 'bg-gold-600 hover:bg-gold-700 active:scale-[0.98]'">
                        {{ passForm.processing ? 'جاري التغيير...' : '🔑 تغيير كلمة المرور' }}
                    </button>
                </form>
            </div>
        </div>
    </component>
</template>

<script setup>
import { computed } from 'vue';
import { usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import EmployeeLayout from '@/Components/Layout/EmployeeLayout.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const isDark = computed(() => document.documentElement.classList.contains('dark'));

// Choose layout based on role
const layoutComponent = computed(() => {
    if (page.props.auth?.isAdmin) return AppLayout;
    return EmployeeLayout;
});

// Info form
const infoForm = useForm({
    name: user.value?.name || '',
    email: user.value?.email || '',
    phone: user.value?.phone || '',
});

const saveInfo = () => {
    infoForm.put('/profile', {
        preserveScroll: true,
        onSuccess: () => {},
    });
};

// Password form
const passForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const savePassword = () => {
    passForm.put('/profile/password', {
        preserveScroll: true,
        onSuccess: () => {
            passForm.reset();
        },
    });
};
</script>
