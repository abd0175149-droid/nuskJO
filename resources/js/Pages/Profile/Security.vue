<template>
    <SmartLayout>
        <template #header>الأمان والتحقق بخطوتين</template>
        <div class="max-w-2xl mx-auto space-y-5">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-white">🔐 الأمان والتحقق بخطوتين</h1>
                <p class="text-sm mt-1 text-gray-500 dark:text-gray-400">كلمة المرور وحدها لا تكفي إن سُرقت: رمز من هاتفك عند كل دخول جديد وقبل الأفعال الحساسة.</p>
            </div>

            <div v-if="$page.props.flash?.error" role="alert" class="p-3 rounded-xl border text-sm font-bold bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">{{ $page.props.flash.error }}</div>
            <div v-if="error && !setup" role="alert" class="p-3 rounded-xl border text-sm font-bold bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">{{ error }}</div>
            <div v-if="notice" role="status" class="p-3 rounded-xl border text-sm font-bold bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ notice }}</div>

            <!-- الحالة -->
            <section class="rounded-xl border p-5 bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-800 space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-bold text-gray-800 dark:text-gray-100 me-auto">تطبيق المصادقة</h2>
                    <span v-if="security.required" class="px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">مطلوب لصلاحياتك</span>
                    <span v-if="security.enabled" class="px-2 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">✓ مفعّل منذ <span dir="ltr">{{ security.confirmed_at }}</span></span>
                    <span v-else class="px-2 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">غير مفعّل</span>
                </div>
                <p v-if="!security.enabled && security.required && security.enforcement === 'on'" class="text-sm text-red-700 dark:text-red-400 font-bold">صلاحياتك تتطلب التحقق بخطوتين قبل المتابعة إلى أي صفحة أخرى.</p>
                <p v-else-if="!security.enabled && security.required && security.enforce_from" class="text-sm text-amber-700 dark:text-amber-400">فعّله قبل <span dir="ltr">{{ security.enforce_from }}</span>، فبعده لا تُفتح الصفحات الأخرى قبل تفعيله.</p>
                <p v-if="security.last_login_at" class="text-xs text-gray-500 dark:text-gray-400">آخر دخول: <span dir="ltr">{{ security.last_login_at }}</span><template v-if="security.last_login_ip"> من <span dir="ltr" class="font-mono">{{ security.last_login_ip }}</span></template>. إن لم يكن هذا أنت فغيّر كلمة مرورك.</p>

                <!-- التفعيل -->
                <template v-if="!security.enabled">
                    <p v-if="!security.enrollment_open" class="text-sm text-gray-500 dark:text-gray-400">التحقق بخطوتين غير متاح بعد. سيُعلَن حين يفعّله المدير.</p>
                    <template v-else-if="!setup">
                        <ol class="text-sm text-gray-600 dark:text-gray-300 list-decimal ps-5 space-y-1">
                            <li>ثبّت على هاتفك الشخصي تطبيق مصادقة، مثل Google Authenticator أو Microsoft Authenticator.</li>
                            <li>اضغط «تفعيل» وامسح الرمز بالتطبيق، ثم اكتب الرقم الذي يظهر فيه.</li>
                            <li>احفظ رموز الاسترداد العشرة: هي طريقك للدخول إن فقدت الهاتف.</li>
                        </ol>
                        <button type="button" @click="start" :disabled="busy" class="min-h-11 px-5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
                            {{ busy ? 'جارٍ التحضير…' : '🔐 تفعيل تطبيق المصادقة' }}
                        </button>
                    </template>
                    <div v-else class="grid gap-4 sm:grid-cols-[auto,1fr] items-start">
                        <img :src="setup.qr" alt="رمز QR لتطبيق المصادقة" width="200" height="200" class="w-48 h-48 sm:w-52 sm:h-52 rounded-lg border border-gray-200 dark:border-gray-700 bg-white mx-auto" />
                        <form @submit.prevent="confirmCode" class="space-y-3 min-w-0">
                            <div>
                                <p class="text-xs font-bold text-gray-500 dark:text-gray-400">لا تستطيع المسح؟ اكتب هذا المفتاح في التطبيق:</p>
                                <p dir="ltr" class="mt-1 font-mono text-sm break-all select-all text-gray-800 dark:text-gray-100">{{ setup.secret }}</p>
                            </div>
                            <div>
                                <label for="mfa-code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الرقم الظاهر في التطبيق</label>
                                <input id="mfa-code" ref="codeInput" v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" dir="ltr"
                                       class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white text-center text-lg tracking-[0.4em] font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none"
                                       :aria-invalid="error ? 'true' : undefined" />
                                <p v-if="error" role="alert" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ error }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" :disabled="busy || !code.trim()" class="min-h-11 px-5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">{{ busy ? 'جارٍ التحقق…' : 'تأكيد وتفعيل' }}</button>
                                <button type="button" @click="setup = null" class="min-h-11 px-4 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
                            </div>
                        </form>
                    </div>
                </template>
            </section>

            <!-- رموز الاسترداد والتعطيل -->
            <section v-if="security.enabled" class="rounded-xl border p-5 bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-800 space-y-3">
                <h2 class="text-base font-bold text-gray-800 dark:text-gray-100">رموز الاسترداد</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">بقي <b>{{ security.recovery_remaining }}</b> من 10.
                    <span v-if="security.recovery_remaining <= 3" class="text-amber-700 dark:text-amber-400 font-bold">جدّدها الآن.</span></p>
                <button type="button" @click="regenerate" :disabled="busy" class="min-h-11 px-4 rounded-xl text-sm font-bold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50">🔄 تجديد الرموز</button>
            </section>

            <section v-if="security.enabled" class="rounded-xl border p-5 bg-white dark:bg-gray-900 border-red-200 dark:border-red-900/50 space-y-3">
                <h2 class="text-base font-bold text-red-700 dark:text-red-400">تعطيل التحقق بخطوتين</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">لتغيير الهاتف: عطّله ثم فعّله على الجديد. التعطيل يُخرج حسابك من بقية الأجهزة.</p>
                <p v-if="security.disable_blocked" class="text-sm text-amber-700 dark:text-amber-400">{{ security.disable_blocked }}</p>
                <button type="button" @click="disable" :disabled="busy || !!security.disable_blocked" class="min-h-11 px-4 rounded-xl text-sm font-bold text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800 hover:bg-red-50 dark:hover:bg-red-900/20 disabled:opacity-50">تعطيل</button>
            </section>
        </div>

        <RecoveryCodesModal :codes="codes" @close="codes = null" />
    </SmartLayout>
</template>

<script setup>
import { nextTick, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import SmartLayout from '@/Components/Layout/SmartLayout.vue';
import RecoveryCodesModal from '@/Components/Security/RecoveryCodesModal.vue';
import { useConfirm } from '@/composables/useConfirm';

defineProps({ security: { type: Object, required: true } });

const { confirm } = useConfirm();
const setup = ref(null);
const code = ref('');
const codes = ref(null);
const error = ref('');
const notice = ref('');
const busy = ref(false);
const codeInput = ref(null);

const message = (e) => Object.values(e?.response?.data?.errors || {})[0]?.[0] || e?.response?.data?.message || 'تعذّر الطلب. أعد المحاولة.';
const refresh = () => router.reload({ only: ['security'] });

async function run(fn) {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        await fn();
    } catch (e) {
        // إلغاء نافذة تأكيد الهوية (423) لا يُعرض خطأً
        if (e?.response?.status !== 423) error.value = message(e);
    } finally {
        busy.value = false;
    }
}

const start = () => run(async () => {
    const { data } = await axios.post('/profile/two-factor');
    setup.value = data;
    code.value = '';
    await nextTick();
    codeInput.value?.focus();
});

const confirmCode = () => run(async () => {
    const { data } = await axios.post('/profile/two-factor/confirm', { code: code.value.trim() });
    setup.value = null;
    codes.value = data.recovery_codes;
    notice.value = 'فُعّل التحقق بخطوتين. احفظ رموز الاسترداد.';
    refresh();
});

const regenerate = async () => {
    if (!(await confirm({ title: 'تجديد رموز الاسترداد', message: 'تُبطل الرموز القديمة كلها وتظهر عشرة جديدة مرة واحدة.', confirmLabel: '🔄 تجديد' }))) return;
    run(async () => {
        const { data } = await axios.post('/profile/two-factor/recovery-codes');
        codes.value = data.recovery_codes;
        refresh();
    });
};

const disable = async () => {
    if (!(await confirm({ title: 'تعطيل التحقق بخطوتين', message: 'يكفي بعدها كلمة المرور للدخول، وتخرج من بقية الأجهزة. متأكد؟', confirmLabel: 'تعطيل', danger: true }))) return;
    run(async () => {
        await axios.delete('/profile/two-factor');
        notice.value = 'عُطّل التحقق بخطوتين.';
        refresh();
    });
};
</script>
