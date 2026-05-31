<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-900 relative overflow-hidden">
        <!-- Background Pattern -->
        <div class="absolute inset-0 opacity-5">
            <div class="absolute inset-0" style="background-image: url('data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23d4a017\' fill-opacity=\'0.4\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');"></div>
        </div>

        <!-- Login Card -->
        <div class="relative z-10 w-full max-w-md mx-4">
            <div class="bg-gray-800 rounded-2xl shadow-2xl border border-gold-900/30 p-8">
                <!-- Logo -->
                <div class="text-center mb-8">
                    <div class="w-56 mx-auto mb-4">
                        <img src="/images/logo-company.png?v=3"
                             alt="شركة نسك للسياحة والسفر"
                             class="w-full object-contain" />
                    </div>
                    <p class="text-gray-400 text-sm">نظام إدارة العمليات المالية</p>
                </div>

                <!-- Form -->
                <form @submit.prevent="submit" class="space-y-5">
                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-300 mb-2">البريد الإلكتروني</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autofocus
                            dir="ltr"
                            class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gold-500 focus:border-transparent transition-all"
                            placeholder="admin@nusuk-jo.com"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-400">{{ form.errors.email }}</p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-300 mb-2">كلمة المرور</label>
                        <div class="relative">
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                required
                                dir="ltr"
                                class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gold-500 focus:border-transparent transition-all"
                                placeholder="••••••••"
                            />
                            <button type="button" @click="showPassword = !showPassword"
                                    class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gold-400 transition-colors">
                                {{ showPassword ? '🙈' : '👁️' }}
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1 text-sm text-red-400">{{ form.errors.password }}</p>
                    </div>

                    <!-- Remember Me -->
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.remember" type="checkbox"
                                   class="w-4 h-4 bg-gray-700 border-gray-600 rounded text-gold-500 focus:ring-gold-500" />
                            <span class="text-sm text-gray-400">تذكرني</span>
                        </label>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full py-3 px-4 rounded-xl font-bold text-black transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="form.processing
                            ? 'bg-gold-700'
                            : 'bg-gradient-to-r from-gold-500 to-gold-400 hover:from-gold-400 hover:to-gold-300 shadow-lg hover:shadow-gold-500/25'"
                    >
                        <span v-if="form.processing" class="flex items-center justify-center gap-2">
                            <span class="animate-spin">⏳</span> جارٍ الدخول...
                        </span>
                        <span v-else>تسجيل الدخول</span>
                    </button>
                </form>
            </div>

            <!-- Footer -->
            <p class="text-center text-gray-500 text-xs mt-6">
                شركة نسك للسياحة والسفر © {{ new Date().getFullYear() }} — جميع الحقوق محفوظة
            </p>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>
