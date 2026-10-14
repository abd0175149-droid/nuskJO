<template>
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6">
        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-1">🪪 هوية العملاء (BSUID)</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
            عميل يراسل باسم مستخدم واتساب ورقمه غير ظاهر: كيف نتعامل معه. لا بيانات مالية له في أي وضع حتى يشارك رقمه.
        </p>
        <div class="space-y-2" role="radiogroup" aria-label="وضع العميل بلا رقم">
            <label v-for="m in MODES" :key="m.key"
                   class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer"
                   :class="current === m.key ? 'border-gold-400 bg-gold-50/60 dark:bg-gold-900/10' : 'border-gray-200 dark:border-gray-700'">
                <input type="radio" name="identity-mode" :value="m.key" :checked="current === m.key" :disabled="busy"
                       @change="choose(m.key)" class="mt-1 w-4 h-4 text-gold-500 shrink-0"/>
                <span class="min-w-0">
                    <span class="block text-sm font-bold text-gray-800 dark:text-gray-100">{{ m.label }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ m.hint }}</span>
                </span>
            </label>
        </div>
        <p v-if="saved" class="text-xs text-green-700 dark:text-green-400 mt-2">✅ حُفظ</p>
        <p v-if="error" class="text-xs text-red-600 dark:text-red-400 mt-2" role="alert">{{ error }}</p>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({ mode: { type: String, default: 'store' } });

const MODES = [
    { key: 'store', label: 'حفظ فقط', hint: 'تُحفظ الرسائل ويُنبَّه الموظفون، بلا إرسال بالمعرّف.' },
    { key: 'staff', label: 'الموظف يرد', hint: 'الموظف يرد ويطلب الرقم بزر «طلب الرقم»؛ البوت لا يرد.' },
    { key: 'full', label: 'البوت والموظف', hint: 'البوت يرد أيضاً ويرسل زر مشاركة الرقم مع طلب التسعير، بلا بيانات مالية.' },
];

const current = ref(props.mode);
const busy = ref(false);
const saved = ref(false);
const error = ref('');
watch(() => props.mode, (v) => { current.value = v; });

const choose = async (mode) => {
    if (mode === current.value || busy.value) return;
    const before = current.value;
    current.value = mode;
    busy.value = true; saved.value = false; error.value = '';
    try {
        const { data } = await axios.patch('/api/whatsapp/settings/identity-mode', { mode });
        current.value = data.identityMode;
        saved.value = true;
    } catch (e) {
        current.value = before;
        error.value = e?.response?.data?.message || 'تعذّر الحفظ';
    } finally {
        busy.value = false;
    }
};
</script>
