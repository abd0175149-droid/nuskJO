<template>
    <Head title="التحقق بخطوتين" />
    <div class="min-h-screen flex items-center justify-center bg-gray-900 relative overflow-hidden px-4">
        <div class="relative z-10 w-full max-w-md">
            <div class="bg-gray-800 rounded-2xl shadow-2xl border border-gold-900/30 p-6 sm:p-8">
                <div class="text-center mb-6">
                    <div class="w-40 mx-auto mb-4">
                        <img src="/images/logo-company.png?v=3" alt="شركة نسك للسياحة والسفر" class="w-full object-contain" />
                    </div>
                    <h1 class="text-lg font-bold text-white">🔐 التحقق بخطوتين</h1>
                    <p class="text-gray-400 text-sm mt-1">
                        {{ recovery ? 'اكتب أحد رموز الاسترداد التي حفظتها عند التفعيل.' : 'اكتب الرمز الظاهر الآن في تطبيق المصادقة على هاتفك.' }}
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    <div v-if="!recovery">
                        <label for="code" class="block text-sm font-medium text-gray-300 mb-2">رمز التحقق</label>
                        <input id="code" ref="codeInput" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" dir="ltr" autofocus
                               class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white text-center text-2xl tracking-[0.5em] font-mono placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gold-500 focus:border-transparent"
                               placeholder="000000" :aria-invalid="form.errors.code ? 'true' : undefined" />
                        <p v-if="form.errors.code" role="alert" class="mt-2 text-sm text-red-400">{{ form.errors.code }}</p>
                    </div>
                    <div v-else>
                        <label for="recovery" class="block text-sm font-medium text-gray-300 mb-2">رمز الاسترداد</label>
                        <input id="recovery" ref="recoveryInput" v-model="form.recovery_code" autocomplete="off" maxlength="20" dir="ltr"
                               class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white text-center text-lg tracking-widest font-mono uppercase placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gold-500 focus:border-transparent"
                               placeholder="XXXXX-XXXXX" :aria-invalid="form.errors.recovery_code ? 'true' : undefined" />
                        <p v-if="form.errors.recovery_code" role="alert" class="mt-2 text-sm text-red-400">{{ form.errors.recovery_code }}</p>
                    </div>

                    <button type="submit" :disabled="form.processing || !filled"
                            class="w-full py-3 px-4 rounded-xl font-bold text-black transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed bg-gradient-to-r from-gold-500 to-gold-400 hover:from-gold-400 hover:to-gold-300 shadow-lg">
                        {{ form.processing ? 'جارٍ التحقق…' : 'متابعة' }}
                    </button>

                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <button type="button" @click="toggle" class="min-h-11 px-2 text-gold-400 hover:text-gold-300 underline underline-offset-4">
                            {{ recovery ? 'استعمل رمز التطبيق' : 'فقدت هاتفي؟ استعمل رمز استرداد' }}
                        </button>
                        <button type="button" @click="logout" class="min-h-11 px-2 text-gray-400 hover:text-red-400">خروج</button>
                    </div>
                </form>

                <p class="mt-5 text-xs leading-5 text-gray-500">
                    لا هاتف ولا رموز استرداد؟ اطلب من المدير إعادة تعيين التحقق بخطوتين لحسابك، ثم سجّل هاتفك من جديد.
                </p>
            </div>
            <p class="text-center text-gray-500 text-xs mt-6">{{ name }}</p>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps({ name: { type: String, default: '' } });

const recovery = ref(false);
const codeInput = ref(null);
const recoveryInput = ref(null);
const form = useForm({ code: '', recovery_code: '' });
const filled = computed(() => (recovery.value ? form.recovery_code : form.code).trim() !== '');

const toggle = async () => {
    recovery.value = !recovery.value;
    form.clearErrors();
    form.code = '';
    form.recovery_code = '';
    await nextTick();
    (recovery.value ? recoveryInput : codeInput).value?.focus();
};

const submit = () => {
    form.transform((data) => (recovery.value ? { recovery_code: data.recovery_code } : { code: data.code }))
        .post('/two-factor/challenge', { onFinish: () => form.reset('code', 'recovery_code') });
};

const logout = () => router.post('/logout');
</script>
