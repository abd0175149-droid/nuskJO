<template>
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-1">🚩 الميزات التجريبية</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">تُشحن كل ميزة جديدة مطفأة، وتُفعَّل من هنا بسبب مكتوب. البيئة تتقدّم دائماً للإطفاء الطارئ.</p>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            <li v-for="f in list" :key="f.key" class="py-3 flex items-center justify-between gap-3 flex-wrap">
                <div class="min-w-0">
                    <p class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ f.label }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <bdi dir="ltr" class="font-mono">{{ f.key }}</bdi> ·
                        {{ !f.available ? 'لم تُشحن بعد' : (f.on ? 'مفعّلة' : 'مطفأة') }}
                        <template v-if="f.available"> · {{ SOURCE[f.source] }}</template>
                    </p>
                </div>
                <div v-if="f.available && f.source !== 'env'" class="flex gap-1">
                    <button v-for="s in ['on', 'off', 'default']" :key="s" type="button" @click="toggle(f, s)" :disabled="busy"
                            class="px-2.5 py-1 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40">
                        {{ { on: 'تشغيل', off: 'إطفاء', default: 'الافتراضي' }[s] }}
                    </button>
                </div>
            </li>
        </ul>
        <p v-if="error" class="text-xs text-red-600 dark:text-red-400 mt-2">{{ error }}</p>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';

// FeatureFlags::panel(): الميزة غير المشحونة تظهر للعلم فقط، والمفروضة من البيئة لا تُبدَّل
const props = defineProps({ flags: { type: Array, default: () => [] } });

const SOURCE = { env: 'من بيئة الخادم', db: 'من هنا', default: 'الافتراضي' };
const list = ref([...props.flags]);
watch(() => props.flags, (v) => { list.value = [...v]; });
const busy = ref(false);
const error = ref('');

const toggle = async (flag, state) => {
    const reason = window.prompt(`سبب ${state === 'on' ? 'تشغيل' : state === 'off' ? 'إطفاء' : 'إرجاع'} «${flag.label}»:`);
    if (!reason || reason.trim().length < 5) return;
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.patch(`/api/whatsapp/settings/flags/${flag.key}`, { state, reason });
        list.value = data.flags;
    } catch (e) {
        error.value = e.response?.data?.message || 'تعذّر التبديل';
    } finally {
        busy.value = false;
    }
};
</script>
