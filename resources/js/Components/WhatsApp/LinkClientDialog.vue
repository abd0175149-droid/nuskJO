<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @keydown.esc="close" @click.self="close">
        <div ref="panel" class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md p-5 space-y-4" dir="rtl"
             role="dialog" aria-modal="true" aria-labelledby="link-client-title" @keydown.tab="trapFocus">
            <div class="flex items-center justify-between gap-2">
                <h3 id="link-client-title" class="text-base font-bold text-gray-800 dark:text-gray-100">🔗 ربط المحادثة بعميل</h3>
                <button type="button" @click="close" aria-label="إغلاق" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">✕</button>
            </div>

            <template v-if="!chosen">
                <input ref="searchInput" v-model="q" type="search" placeholder="اسم العميل أو كوده أو جزء من رقمه"
                       aria-label="بحث عن عميل" maxlength="60"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white"/>
                <p v-if="q.trim().length && q.trim().length < 2" class="text-xs text-gray-400">اكتب حرفين على الأقل.</p>
                <p v-if="loading" class="text-xs text-gray-400">جارٍ البحث...</p>
                <ul v-else-if="results.length" class="max-h-64 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800 rounded-xl border border-gray-100 dark:border-gray-800">
                    <li v-for="c in results" :key="c.id">
                        <button type="button" @click="choose(c)"
                                class="w-full text-right px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-800/60 flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-gray-800 dark:text-gray-100 truncate">{{ c.name }}</span>
                            <span class="shrink-0 text-[11px] text-gray-500 dark:text-gray-400 font-mono" dir="ltr">{{ c.code }}<span v-if="c.phone_masked"> · {{ c.phone_masked }}</span></span>
                        </button>
                    </li>
                </ul>
                <p v-else-if="searched" class="text-xs text-gray-400">لا نتائج.</p>
            </template>

            <template v-else>
                <p class="text-sm text-gray-800 dark:text-gray-100">
                    سيُربط هذا الحديث بالعميل <strong>{{ chosen.name }}</strong> — <bdi dir="ltr" class="font-mono">{{ chosen.code }}</bdi>
                </p>
                <p v-if="conversation.client_id && conversation.client_id !== chosen.id" class="text-xs text-amber-700 dark:text-amber-400">⚠️ سيُستبدل الربط الحالي ({{ conversation.client }}).</p>
                <p v-if="conversation.phoneless" class="text-xs text-amber-700 dark:text-amber-400">📵 لن يرى البوت رصيده حتى يشارك العميل رقمه.</p>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" @click="chosen = null; error = ''" class="px-4 py-2 rounded-xl text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">رجوع</button>
                    <button type="button" :disabled="saving" @click="confirm"
                            class="px-5 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
                        {{ saving ? '...' : 'تأكيد الربط' }}
                    </button>
                </div>
            </template>

            <p v-if="error" class="text-xs text-red-600 dark:text-red-400" role="alert">❌ {{ error }}</p>
        </div>
    </div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue';
import axios from 'axios';

// conversation: {id, client, client_id, phoneless} — الربط يرسل client_id وclient_code معاً (الخادم يتحقق من التطابق)
const props = defineProps({
    open: Boolean,
    conversation: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'linked']);

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const q = ref('');
const results = ref([]);
const searched = ref(false);
const loading = ref(false);
const chosen = ref(null);
const saving = ref(false);
const error = ref('');
const panel = ref(null);
const searchInput = ref(null);
let timer = null;
let seq = 0;

const reset = () => {
    q.value = ''; results.value = []; searched.value = false; chosen.value = null; error.value = ''; saving.value = false;
};

watch(() => props.open, async (isOpen) => {
    if (!isOpen) return;
    reset();
    await nextTick();
    searchInput.value?.focus();
});

// بحث مؤجّل 300ms؛ الرد المتأخر لبحث أقدم يُهمل
watch(q, (value) => {
    clearTimeout(timer);
    const term = value.trim();
    if (term.length < 2) { results.value = []; searched.value = false; return; }
    timer = setTimeout(async () => {
        const mine = ++seq;
        loading.value = true;
        try {
            const { data } = await axios.get('/api/whatsapp/clients/search', { ...api, params: { q: term } });
            if (mine === seq) { results.value = data.clients || []; searched.value = true; error.value = ''; }
        } catch (e) {
            if (mine === seq) error.value = e?.response?.data?.message || 'تعذّر البحث';
        } finally {
            if (mine === seq) loading.value = false;
        }
    }, 300);
});

const choose = (c) => { chosen.value = c; error.value = ''; };

const confirm = async () => {
    if (!chosen.value || saving.value) return;
    saving.value = true; error.value = '';
    try {
        const { data } = await axios.post(`/api/whatsapp/conversations/${props.conversation.id}/link-client`,
            { client_id: chosen.value.id, client_code: chosen.value.code }, api);
        emit('linked', data);
        emit('close');
    } catch (e) {
        const errs = e?.response?.data?.errors;
        error.value = (errs && Object.values(errs)[0]?.[0]) || e?.response?.data?.message || 'تعذّر الربط';
    } finally {
        saving.value = false;
    }
};

const close = () => { clearTimeout(timer); seq++; emit('close'); };

// حبس التركيز داخل النافذة
const trapFocus = (e) => {
    const nodes = panel.value?.querySelectorAll('button:not([disabled]), input, [tabindex]:not([tabindex="-1"])');
    if (!nodes?.length) return;
    const first = nodes[0], last = nodes[nodes.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
};
</script>
