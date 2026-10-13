<template>
    <!-- إشعارات هذا الجهاز — داخل قائمة الجرس: زر التفعيل، أو حالتها وتجربتها، أو سبب تعذّرها -->
    <div v-if="view" class="p-3 border-b text-xs border-gray-100 dark:border-gray-700">
        <button v-if="view === 'ask'" type="button" @click.stop="enable()" :disabled="busy"
                class="w-full px-3 py-2 rounded-lg font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
            {{ busy ? '...جارٍ التفعيل' : '🔔 تفعيل إشعارات هذا الجهاز' }}
        </button>
        <template v-else-if="view === 'on'">
            <div class="flex items-center justify-between gap-2">
                <span class="text-green-700 dark:text-green-400">✅ إشعارات هذا الجهاز مفعّلة</span>
                <button type="button" @click.stop="test" :disabled="busy" class="text-blue-500 hover:underline disabled:opacity-50">
                    {{ busy ? '...' : 'إرسال إشعار تجريبي' }}
                </button>
            </div>
            <p v-if="note" class="mt-2 text-gray-600 dark:text-gray-300 leading-relaxed">{{ note }}</p>
        </template>
        <template v-else-if="view === 'error'">
            <p class="text-red-600 dark:text-red-400 leading-relaxed">تعذّر تسجيل هذا الجهاز للإشعارات.</p>
            <p v-if="note" class="mt-1 text-gray-500 dark:text-gray-400 break-words" dir="auto">{{ note }}</p>
            <p v-if="isBrave" class="mt-1 text-gray-600 dark:text-gray-300 leading-relaxed">
                في Brave: الإعدادات ← الخصوصية والأمان ← فعّل «استخدام خدمات Google للرسائل الفورية»، ثم أعد المحاولة.
            </p>
            <button type="button" @click.stop="enable({ reset: true })" :disabled="busy"
                    class="mt-2 w-full px-3 py-1.5 rounded-lg font-bold border border-gold-500 text-gold-600 dark:text-gold-400 disabled:opacity-50">
                {{ busy ? '...جارٍ المحاولة' : 'إعادة المحاولة' }}
            </button>
        </template>
        <p v-else-if="view === 'denied'" class="text-amber-700 dark:text-amber-300 leading-relaxed">
            🔕 الإشعارات محظورة لهذا الموقع على هذا الجهاز. افتحها من 🔒 بجانب رابط الموقع ← الإشعارات ← سماح، ثم حدّث الصفحة.
        </p>
        <p v-else-if="view === 'ios'" class="text-gray-600 dark:text-gray-300 leading-relaxed">
            📲 لتصلك الإشعارات على الآيفون: زر المشاركة ← «إضافة إلى الشاشة الرئيسية»، ثم افتح النظام من الأيقونة وفعّلها من هنا.
        </p>
        <p v-else-if="view === 'insecure'" class="text-gray-600 dark:text-gray-300 leading-relaxed">
            🔒 إشعارات الجهاز تعمل فقط حين يُفتح الموقع عبر https.
        </p>
        <p v-else-if="view === 'unsupported'" class="text-gray-600 dark:text-gray-300 leading-relaxed">
            هذا المتصفح لا يدعم إشعارات الجهاز. استعمل Chrome أو Edge أو Firefox أو Safari.
        </p>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { pushStatus, needsIosInstall, enablePush, syncPushToken, pushError, sendTestPush } from '../../firebase';

// إذن ممنوح لا يكفي: الجهاز قد يكون مسجّلاً بتوكن ميت — فيُتحقق من التسجيل أولاً
const state = ref(pushStatus() === 'granted' ? 'checking' : pushStatus());
const busy = ref(false);
const note = ref('');
const isBrave = Boolean(navigator.brave);

const view = computed(() => {
    switch (state.value) {
        case 'default': return 'ask';
        case 'granted': return 'on';
        case 'error': return 'error';
        case 'denied': return 'denied';
        case 'unsupported':
            if (needsIosInstall()) return 'ios';
            return window.isSecureContext === false ? 'insecure' : 'unsupported';
        default: return null; // checking | unconfigured
    }
});

onMounted(async () => {
    if (state.value !== 'checking') return;
    state.value = (await syncPushToken()) ? 'granted' : 'error';
    if (state.value === 'error') note.value = pushError() || '';
});

const enable = async (options) => {
    busy.value = true;
    note.value = '';
    try {
        state.value = await enablePush(options);
    } catch (e) {
        state.value = 'error';
    } finally {
        if (state.value === 'error') note.value = pushError() || '';
        busy.value = false;
    }
};

const test = async () => {
    busy.value = true;
    note.value = '';
    try {
        await sendTestPush();
        note.value = 'أُرسل. إن لم يظهر خلال ثوانٍ فإشعارات المتصفح مكتومة في النظام: '
            + 'في Windows الإعدادات ← النظام ← الإشعارات (وأوقف «عدم الإزعاج»)، وفي Mac إعدادات النظام ← الإشعارات ← المتصفح.';
    } catch (e) {
        // توكن غير مسجّل أو رفضه FCM (ويُحذف عند الخادم): «إعادة المحاولة» تنشئ اشتراكاً جديداً.
        // غير ذلك (Firebase غير مهيأ على الخادم مثلاً) لا يصلحه اشتراك جديد — تبقى الحالة وتظهر الرسالة
        const status = e.response?.status;
        if (!status || status === 404 || status === 422) state.value = 'error';
        note.value = e.response?.data?.message || e.message || '';
    } finally {
        busy.value = false;
    }
};
</script>
