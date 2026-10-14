<template>
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4">
        <div class="flex items-center gap-2 flex-wrap">
            <p class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ secret.label }}</p>
            <span v-if="secret.decrypt_ok === false" class="px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300"
                  title="محفوظ بمفتاح تطبيق آخر: أعد إدخاله، أو أضف المفتاح القديم إلى APP_PREVIOUS_KEYS">⛔ تعذّر فك التشفير</span>
            <SourceBadge v-else :source="{ source: secret.source, env_var: secret.env_var }"/>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            <template v-if="secret.rotated_at">آخر تغيير: <bdi dir="ltr">{{ when(secret.rotated_at) }}</bdi></template>
            <template v-else-if="secret.has">لا تاريخ تغيير (قبل سجل التدوير)</template>
            <slot name="hint"/>
        </p>
        <slot/>
        <div v-if="!isEnv" class="flex flex-wrap gap-2 mt-3">
            <button type="button" @click="openChange" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-xs font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20">
                {{ secret.has ? '🔁 تغيير' : '➕ إدخال' }}
            </button>
            <button v-if="secret.has" type="button" @click="openClear" class="px-3 py-1.5 rounded-lg text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">🗑️ مسح</button>
        </div>
        <p v-else class="text-xs text-blue-700 dark:text-blue-300 mt-2">يُغيَّر في بيئة الخادم (.env.production)، ولها الأولوية على أي قيمة هنا.</p>
        <p v-if="message" role="status" class="text-xs mt-2" :class="ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">{{ message }}</p>

        <!-- نافذة التغيير أو المسح -->
        <div v-if="mode" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="close">
            <form @submit.prevent="submit" class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md p-6 space-y-3" dir="rtl">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ mode === 'set' ? `تغيير ${secret.label}` : `مسح ${secret.label}` }}</h3>
                <p v-if="mode === 'clear' && warnClear" class="text-sm font-bold text-red-700 dark:text-red-400">{{ warnClear }}</p>
                <div v-if="mode === 'set'">
                    <label :for="`secret-${name}`" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">القيمة الجديدة</label>
                    <input :id="`secret-${name}`" v-model="value" type="password" dir="ltr" autocomplete="new-password" required minlength="20"
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                    <p class="text-xs text-gray-500 mt-1">لا تُعرض بعد الحفظ أبداً؛ يظهر أول 8 خانات من بصمتها فقط.</p>
                </div>
                <div>
                    <label :for="`reason-${name}`" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السبب</label>
                    <select :id="`reason-${name}`" v-model="reason" required
                            class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white">
                        <option v-for="(label, key) in SECRET_REASONS" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="close" class="px-4 py-2 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
                    <button type="submit" :disabled="busy" class="px-5 py-2 rounded-xl text-sm font-bold disabled:opacity-50"
                            :class="mode === 'clear' ? 'bg-red-600 text-white' : 'text-black bg-gradient-to-r from-gold-500 to-gold-400'">
                        {{ busy ? '⏳' : (mode === 'clear' ? 'مسح' : 'حفظ') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import SourceBadge from './SourceBadge.vue';
import { SECRET_REASONS, when } from '@/utils/waSettings.js';

// سر واحد: حالته ومصدره وتاريخ تغييره فقط. القيمة تُرسل مرة ولا تعود من الخادم
const props = defineProps({
    name: { type: String, required: true },
    secret: { type: Object, required: true },
    warnClear: { type: String, default: '' },
});
const emit = defineEmits(['changed']);

const isEnv = computed(() => props.secret.source === 'env');
const mode = ref(null);
const value = ref('');
const reason = ref('routine');
const busy = ref(false);
const error = ref('');
const message = ref('');
const ok = ref(false);

const openChange = () => { mode.value = 'set'; value.value = ''; reason.value = props.secret.has ? 'routine' : 'setup'; error.value = ''; };
const openClear = () => { mode.value = 'clear'; reason.value = 'routine'; error.value = ''; };
const close = () => { mode.value = null; value.value = ''; };

const submit = async () => {
    busy.value = true;
    error.value = '';
    try {
        const url = `/api/whatsapp/settings/secrets/${props.name}`;
        const { data } = mode.value === 'set'
            ? await axios.post(url, { value: value.value, reason: reason.value })
            : await axios.delete(url, { data: { reason: reason.value } });
        ok.value = true;
        message.value = mode.value === 'set' ? `✅ حُفظ — البصمة ${data.fingerprint}` : '✅ مُسح';
        emit('changed', { ...props.secret, has: data.has, rotated_at: data.rotated_at ?? props.secret.rotated_at, decrypt_ok: data.has ? true : null, source: data.has ? 'db' : 'unset' });
        close();
    } catch (e) {
        error.value = e.response?.data?.message || Object.values(e.response?.data?.errors || {})[0]?.[0] || 'تعذّر الحفظ';
    } finally {
        busy.value = false;
        value.value = '';
    }
};
</script>
