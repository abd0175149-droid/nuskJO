<template>
    <!-- تفعيل إشعارات الجهاز — داخل قائمة الجرس. يختفي حين تكون مفعّلة أو غير ممكنة على هذا المتصفح -->
    <div v-if="view" class="p-3 border-b text-xs border-gray-100 dark:border-gray-700">
        <button v-if="view === 'ask'" type="button" @click.stop="enable" :disabled="busy"
                class="w-full px-3 py-2 rounded-lg font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
            {{ busy ? '...جارٍ التفعيل' : '🔔 تفعيل إشعارات هذا الجهاز' }}
        </button>
        <p v-else-if="view === 'denied'" class="text-amber-700 dark:text-amber-300 leading-relaxed">
            🔕 الإشعارات محظورة لهذا الموقع على هذا الجهاز. افتحها من 🔒 بجانب رابط الموقع ← الإشعارات ← سماح، ثم حدّث الصفحة.
        </p>
        <p v-else-if="view === 'ios'" class="text-gray-600 dark:text-gray-300 leading-relaxed">
            📲 لتصلك الإشعارات على الآيفون: زر المشاركة ← «إضافة إلى الشاشة الرئيسية»، ثم افتح النظام من الأيقونة وفعّلها من هنا.
        </p>
        <p v-else-if="view === 'error'" class="text-red-600 dark:text-red-400">تعذّر تفعيل الإشعارات الآن. حاول مرة أخرى بعد قليل.</p>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { pushStatus, needsIosInstall, enablePush } from '../../firebase';

const status = ref(pushStatus());
const busy = ref(false);

const view = computed(() => {
    if (status.value === 'default') return 'ask';
    if (status.value === 'denied') return 'denied';
    if (status.value === 'error') return 'error';
    if (status.value === 'unsupported' && needsIosInstall()) return 'ios';
    return null;
});

const enable = async () => {
    busy.value = true;
    try {
        status.value = await enablePush();
    } catch (e) {
        status.value = 'error';
    } finally {
        busy.value = false;
    }
};
</script>
