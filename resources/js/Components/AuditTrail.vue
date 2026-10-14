<template>
    <div v-if="visible" class="mt-4" dir="rtl">
        <button type="button" @click="toggle" class="text-sm font-bold text-gray-700 dark:text-gray-200 flex items-center gap-2">
            🧾 تاريخ التعديلات <span class="text-[10px] transition-transform" :class="open ? 'rotate-180' : ''">▼</span>
        </button>
        <div v-if="open" class="mt-2 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <p v-if="loading" class="p-3 text-xs text-gray-400">جارٍ التحميل…</p>
            <p v-else-if="!rows.length" class="p-3 text-xs text-gray-400">لا صفوف في السجل لهذا {{ entityLabel(type) }}.</p>
            <ul v-else class="divide-y divide-gray-100 dark:divide-gray-800 text-xs">
                <li v-for="r in rows" :key="r.id" class="px-3 py-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="font-mono text-gray-400" dir="ltr">{{ when(r.created_at) }}</span>
                    <span class="font-bold" :class="r.severity === 'error' ? 'text-red-600' : r.severity === 'warn' ? 'text-amber-600' : 'text-gray-800 dark:text-gray-200'">{{ r.action_label }}</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ r.actor_label }}</span>
                    <span v-if="r.summary" class="w-full font-mono text-gray-400 break-all" dir="ltr">{{ r.summary }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { entityLabel, when } from '@/utils/audit.js';

// يظهر لمن يملك audit.view والصفحة مفعّلة؛ ويُحمَّل عند الفتح فقط
const props = defineProps({ type: { type: String, required: true }, id: { type: [Number, String], default: null } });

const page = usePage();
const visible = computed(() => props.id && page.props.features?.audit_page
    && (page.props.auth?.isAdmin || page.props.auth?.permissions?.includes('audit.view')));

const open = ref(false);
const loading = ref(false);
const rows = ref([]);

const load = async () => {
    loading.value = true;
    try {
        const res = await fetch(`/api/audit-logs/entity?type=${encodeURIComponent(props.type)}&id=${props.id}`, { headers: { Accept: 'application/json' } });
        rows.value = res.ok ? await res.json() : [];
    } finally {
        loading.value = false;
    }
};
const toggle = () => { open.value = !open.value; if (open.value) load(); };
watch(() => props.id, () => { rows.value = []; if (open.value) load(); });
</script>
