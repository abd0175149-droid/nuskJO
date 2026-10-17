<template>
    <Head title="تأكيد هويتك" />
    <div class="min-h-screen flex items-center justify-center bg-gray-900 relative overflow-hidden px-4">
        <div class="relative z-10 w-full max-w-md">
            <div class="bg-gray-800 rounded-2xl shadow-2xl border border-gold-900/30 p-6 sm:p-8">
                <div class="text-center mb-6">
                    <h1 class="text-lg font-bold text-white">🔐 تأكيد هويتك</h1>
                    <p class="text-gray-400 text-sm mt-1">
                        {{ method === 'totp' ? 'الصفحة التالية حساسة. اكتب الرمز الظاهر الآن في تطبيق المصادقة.' : 'الصفحة التالية حساسة. أكّد كلمة مرورك للمتابعة.' }}
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    <div v-if="method === 'totp'">
                        <label for="code" class="block text-sm font-medium text-gray-300 mb-2">رمز التحقق</label>
                        <input id="code" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" dir="ltr" autofocus
                               class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white text-center text-2xl tracking-[0.5em] font-mono placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gold-500 focus:border-transparent"
                               placeholder="000000" :aria-invalid="form.errors.code ? 'true' : undefined" />
                        <p v-if="form.errors.code" role="alert" class="mt-2 text-sm text-red-400">{{ form.errors.code }}</p>
                    </div>
                    <div v-else>
                        <label for="password" class="block text-sm font-medium text-gray-300 mb-2">كلمة المرور</label>
                        <input id="password" v-model="form.password" type="password" autocomplete="current-password" dir="ltr" autofocus
                               class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gold-500 focus:border-transparent"
                               :aria-invalid="form.errors.password ? 'true' : undefined" />
                        <p v-if="form.errors.password" role="alert" class="mt-2 text-sm text-red-400">{{ form.errors.password }}</p>
                    </div>

                    <button type="submit" :disabled="form.processing"
                            class="w-full py-3 px-4 rounded-xl font-bold text-black disabled:opacity-50 bg-gradient-to-r from-gold-500 to-gold-400 hover:from-gold-400 hover:to-gold-300 shadow-lg">
                        {{ form.processing ? 'جارٍ التحقق…' : 'تأكيد ومتابعة' }}
                    </button>
                    <Link href="/" class="block text-center min-h-11 leading-[2.75rem] text-sm text-gray-400 hover:text-gold-400">رجوع للرئيسية</Link>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ method: { type: String, default: 'password' } });

const form = useForm({ code: '', password: '' });

const submit = () => {
    form.transform((data) => (props.method === 'totp' ? { code: data.code } : { password: data.password }))
        .post('/security/confirm', { onFinish: () => form.reset() });
};
</script>
