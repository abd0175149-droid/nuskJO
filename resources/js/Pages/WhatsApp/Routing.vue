<template>
    <AppLayout>
        <template #header>توجيه محادثات الواتساب</template>

        <div class="space-y-6 pb-4">
            <!-- ===== رسائل Flash ===== -->
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">❌ {{ $page.props.flash.error }}</div>
            <div v-if="errorList.length" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">
                ❌ تعذّر الحفظ:
                <ul class="list-disc mt-1 pr-5 space-y-0.5">
                    <li v-for="(msg, key) in errorList" :key="key">{{ msg }}</li>
                </ul>
            </div>

            <!-- ===== شرح الشاشة ===== -->
            <div class="p-4 rounded-xl border text-sm leading-relaxed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 text-gray-600 dark:text-gray-300">
                كل محادثة يضع لها البوت وسماً حسب موضوعها. الموظف يرى المحادثات التي تحمل وسماً من اختصاصه فقط — ومحادثة بوسمين يراها الموظفان معاً. الموظف الأساسي هو من يصله الإشعار عند تحويل المحادثة.
            </div>

            <!-- ===== بطاقات المواضيع ===== -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div v-for="(t, ti) in local" :key="t.topic"
                     class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-4 md:p-5 space-y-3">

                    <!-- العنوان -->
                    <div class="flex items-center justify-between gap-2 flex-wrap pb-2 border-b border-gray-100 dark:border-gray-700/60">
                        <h3 class="text-base font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2 min-w-0">
                            <span aria-hidden="true">{{ icon(t.topic) }}</span>
                            <span class="truncate">{{ t.label }}</span>
                            <span class="shrink-0 text-xs font-mono font-normal text-gray-400 dark:text-gray-500" dir="ltr">{{ t.topic }}</span>
                        </h3>
                        <span class="shrink-0 px-2.5 py-1 rounded-full text-xs font-bold tabular-nums"
                              :class="t.users.length
                                  ? 'bg-gold-100 text-gold-800 dark:bg-gold-900/40 dark:text-gold-200'
                                  : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'">
                            {{ t.users.length ? t.users.length + ' مختصّ' : 'بلا مختصّ' }}
                        </span>
                    </div>

                    <!-- المختصّون الحاليون -->
                    <div v-if="t.users.length" class="flex flex-wrap gap-2">
                        <span v-for="(u, ui) in t.users" :key="t.topic + '-' + u.user_id"
                              class="inline-flex items-center gap-1.5 max-w-full pr-2.5 pl-1 py-1 rounded-full border text-xs font-bold"
                              :class="u.is_primary
                                  ? 'border-gold-400 dark:border-gold-600 bg-gold-50 dark:bg-gold-900/30 text-gold-800 dark:text-gold-200'
                                  : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 text-gray-700 dark:text-gray-200'">
                            <span class="truncate">{{ u.name }}</span>
                            <button type="button"
                                    @click="togglePrimary(ti, ui)"
                                    :aria-pressed="u.is_primary ? 'true' : 'false'"
                                    :aria-label="(u.is_primary ? 'إلغاء كون ' : 'جعل ') + u.name + ' الموظف الأساسي لموضوع ' + t.label"
                                    :title="u.is_primary ? 'الموظف الأساسي — اضغط للإلغاء' : 'اجعله الموظف الأساسي'"
                                    class="shrink-0 w-6 h-6 rounded-full leading-none flex items-center justify-center hover:bg-gold-100 dark:hover:bg-gold-900/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500"
                                    :class="u.is_primary ? '' : 'opacity-40 hover:opacity-100'">
                                <span aria-hidden="true">⭐</span>
                            </button>
                            <button type="button"
                                    @click="removeUser(ti, ui)"
                                    :aria-label="'إزالة ' + u.name + ' من موضوع ' + t.label"
                                    title="إزالة"
                                    class="shrink-0 w-6 h-6 rounded-full leading-none flex items-center justify-center text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </span>
                    </div>

                    <!-- لا مختصّ -->
                    <p v-else class="text-xs font-bold text-amber-600 dark:text-amber-400">
                        لا مختصّ — لن يُوكَّل أحد تلقائياً
                    </p>

                    <!-- تنبيه غياب الأساسي -->
                    <p v-if="t.users.length && !t.users.some(u => u.is_primary)" class="text-xs font-bold text-amber-600 dark:text-amber-400">
                        اختر موظفاً أساسياً لاستلام الإشعارات
                    </p>

                    <!-- إضافة مختصّ -->
                    <div class="flex flex-col sm:flex-row gap-2">
                        <label class="sr-only" :for="'add-' + t.topic">إضافة موظف إلى موضوع {{ t.label }}</label>
                        <select :id="'add-' + t.topic" v-model="picked[t.topic]" :disabled="!available(t).length"
                                class="flex-1 min-w-0 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed">
                            <option value="">{{ available(t).length ? '— اختر موظفاً —' : '— لا موظفين متاحين —' }}</option>
                            <option v-for="u in available(t)" :key="t.topic + '-opt-' + u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <button type="button" @click="addUser(ti)" :disabled="!picked[t.topic]"
                                :aria-label="'إضافة الموظف المحدّد إلى موضوع ' + t.label"
                                class="shrink-0 px-4 py-2.5 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 hover:bg-gold-200 dark:hover:bg-gold-900/50 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-500">
                            + إضافة
                        </button>
                    </div>
                </div>
            </div>

            <!-- ===== شريط الحفظ ===== -->
            <div class="sticky bottom-0 z-10 -mx-1 px-1 py-3">
                <div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white/95 dark:bg-gray-900/95 backdrop-blur shadow-md p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <p v-if="dirty" class="text-xs font-bold text-amber-600 dark:text-amber-400 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 rounded-full bg-amber-500 shrink-0" aria-hidden="true"></span>
                        تعديلات غير محفوظة
                    </p>
                    <p v-else class="text-xs text-gray-500 dark:text-gray-400">لا تعديلات غير محفوظة.</p>

                    <button type="button" @click="save" :disabled="saving"
                            class="w-full sm:w-auto px-8 py-3 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-600">
                        {{ saving ? '...جارٍ الحفظ' : '💾 حفظ التوجيه' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
    title: String,
    topics: Array,  // [{ topic, label, users:[{user_id, name, is_primary}] }]
    users: Array,   // [{ id, name }]
});

const TOPIC_ICONS = {
    umrah: '🕋',
    hajj: '🕌',
    package: '🎒',
    tour: '🗺️',
    visa: '🛂',
    flight: '✈️',
    hotel: '🏨',
    transport: '🚌',
    complaint: '⚠️',
    other: '💬',
};

const icon = (key) => TOPIC_ICONS[key] || '🏷️';

// نسخة محلّية عميقة — لا نلمس الـ props أبداً
const clone = (list) => (list || []).map((t) => ({
    topic: t.topic,
    label: t.label,
    users: (t.users || []).map((u) => ({
        user_id: Number(u.user_id),
        name: u.name,
        is_primary: !!u.is_primary,
    })),
}));

const snapshot = (list) => JSON.stringify(
    (list || []).map((t) => ({
        topic: t.topic,
        users: (t.users || []).map((u) => ({ id: Number(u.user_id), p: !!u.is_primary })),
    }))
);

const local = ref(clone(props.topics));
const original = ref(snapshot(local.value));

// المحدّد في قائمة الإضافة لكل موضوع
const picked = reactive({});
local.value.forEach((t) => { picked[t.topic] = ''; });

const saving = ref(false);
const errorList = ref([]);

const dirty = computed(() => snapshot(local.value) !== original.value);

// الموظفون غير المضافين لهذا الموضوع
const available = (t) => {
    const taken = new Set(t.users.map((u) => Number(u.user_id)));
    return (props.users || []).filter((u) => !taken.has(Number(u.id)));
};

const addUser = (ti) => {
    const t = local.value[ti];
    const id = Number(picked[t.topic]);
    if (!id) return;
    if (t.users.some((u) => Number(u.user_id) === id)) return;

    const found = (props.users || []).find((u) => Number(u.id) === id);
    if (!found) return;

    t.users.push({
        user_id: id,
        name: found.name,
        is_primary: !t.users.some((u) => u.is_primary), // أول موظف يصبح الأساسي تلقائياً
    });
    picked[t.topic] = '';
};

const removeUser = (ti, ui) => {
    local.value[ti].users.splice(ui, 1);
};

// أساسيّ واحد فقط لكل موضوع — الضغط على آخر يُنقل النجمة
const togglePrimary = (ti, ui) => {
    const users = local.value[ti].users;
    const next = !users[ui].is_primary;
    users.forEach((u, i) => { u.is_primary = next && i === ui; });
};

const save = () => {
    if (saving.value) return;

    router.put('/whatsapp/routing', {
        routes: local.value.map((t) => ({
            topic: t.topic,
            users: t.users.map((u) => ({ user_id: u.user_id, is_primary: !!u.is_primary })),
        })),
    }, {
        preserveScroll: true,
        onStart: () => { saving.value = true; errorList.value = []; },
        onFinish: () => { saving.value = false; },
        onError: (errors) => { errorList.value = Object.values(errors || {}); },
        onSuccess: () => { original.value = snapshot(local.value); },
    });
};

// إعادة المزامنة إذا وصلت خريطة جديدة من الخادم ولا تعديلات معلّقة
watch(() => props.topics, (fresh) => {
    if (dirty.value) return;
    local.value = clone(fresh);
    original.value = snapshot(local.value);
    local.value.forEach((t) => { if (picked[t.topic] === undefined) picked[t.topic] = ''; });
});
</script>
