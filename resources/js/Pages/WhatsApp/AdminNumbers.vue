<template>
    <AppLayout>
        <template #header>أرقام الإدارة</template>

        <div class="space-y-6 pb-4">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">❌ {{ $page.props.flash.error }}</div>

            <!-- ===== كيف يعمل ===== -->
            <div class="p-4 rounded-xl border text-sm leading-relaxed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 text-gray-600 dark:text-gray-300 space-y-1.5">
                <p>من يراسل رقم البوت على واتساب من رقم مسجّل هنا يردّ عليه <b>المساعد الإداري</b> بدل بوت العملاء، ولا يستطيع إلا ما سمحت به لرقمه: تقارير، أو إدارة قاعدة المعرفة، أو تشغيل أدوات البوت وإيقافها.</p>
                <p>أي تعديل يطلبه يُعرض عليه أولاً كمعاينة، ولا يُنفَّذ إلا بعد أن يردّ بـ«تأكيد» في رسالة جديدة. وكل ما يُنفَّذ أو يُرفض يظهر في السجلّ أسفل الصفحة.</p>
                <p v-if="!botReady" class="text-amber-700 dark:text-amber-400 font-bold">⚠️ قناة واتساب غير مهيّأة بعد من إعدادات البوت.</p>
            </div>

            <div class="flex justify-between items-center gap-3 flex-wrap">
                <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">الأرقام <span class="text-sm font-normal text-gray-400">({{ numbers.length }})</span></h3>
                <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ رقم إدارة</button>
            </div>

            <!-- ===== الأرقام ===== -->
            <div v-if="numbers.length" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div v-for="n in numbers" :key="n.id" class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-4 space-y-3"
                     :class="n.is_active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600 opacity-70'">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0">
                            <p class="font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2 flex-wrap">
                                👔 {{ n.name }}
                                <span v-if="!n.is_active" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">موقوف</span>
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><span class="font-mono" dir="ltr">+{{ n.phone }}</span><span v-if="n.user_name"> · حساب: {{ n.user_name }}</span><span v-if="n.user_inactive" class="mr-1 px-1.5 py-0.5 rounded bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 font-bold">المستخدم المرتبط معطّل — يُعامَل كعميل</span><span v-if="n.last_used"> · آخر استخدام {{ n.last_used }}</span></p>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" @click="openForm(n)" class="px-2 py-1 rounded-lg text-xs font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ تعديل</button>
                            <button type="button" @click="remove(n)" class="px-2 py-1 rounded-lg text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">🗑️</button>
                        </div>
                    </div>
                    <p v-if="n.client_match" class="text-xs p-2 rounded-lg bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">⚠️ هذا الرقم مسجّل أيضاً للعميل «{{ n.client_match }}». سيُخدم كإدارة لا كعميل.</p>
                    <div v-for="(keys, group) in groupedOf(n.permissions)" :key="group" class="space-y-1">
                        <p class="text-[11px] font-bold text-gray-400">{{ group }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="k in keys" :key="k" class="px-2 py-0.5 rounded-full text-xs font-bold"
                                  :class="catalog[k]?.write ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-gold-50 text-gold-800 dark:bg-gold-900/30 dark:text-gold-200'">{{ catalog[k]?.label || k }}</span>
                        </div>
                    </div>
                    <p v-if="!n.permissions.length" class="text-xs text-gray-400">لا صلاحيات — يردّ المساعد بأنّه لا يملك أدوات لهذا الرقم.</p>
                    <p v-if="n.notes" class="text-xs text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-800 pt-2">{{ n.notes }}</p>
                </div>
            </div>
            <div v-else class="rounded-xl border-2 border-dashed p-10 text-center space-y-3 border-gray-300 dark:border-gray-600">
                <p class="text-sm text-gray-500 dark:text-gray-400">لا أرقام إدارة بعد. كل من يراسل البوت يُخدم كعميل.</p>
                <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200">+ أضف رقمك</button>
            </div>

            <!-- ===== السجلّ ===== -->
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">سجلّ الإجراءات من واتساب</h3>
                    <span class="text-xs text-gray-400">آخر 40
                        <Link v-if="$page.props.features?.audit_page && ($page.props.auth.isAdmin || $page.props.auth.permissions?.includes('audit.view'))"
                              href="/audit-logs?tab=admin" class="ms-2 font-bold text-gold-700 dark:text-gold-400 hover:underline">السجل الكامل ←</Link>
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 dark:text-gray-400 text-xs">
                            <th class="px-4 py-2 text-right font-bold">الوقت</th><th class="px-4 py-2 text-right font-bold">الرقم</th>
                            <th class="px-4 py-2 text-right font-bold">الإجراء</th><th class="px-4 py-2 text-right font-bold">الحالة</th>
                            <th class="px-4 py-2 text-right font-bold">التفاصيل</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="a in actions" :key="a.id" class="border-t border-gray-100 dark:border-gray-800 align-top">
                                <td class="px-4 py-2 font-mono text-xs text-gray-500 whitespace-nowrap" dir="ltr">{{ a.at }}</td>
                                <td class="px-4 py-2 text-xs whitespace-nowrap">{{ a.who }}</td>
                                <td class="px-4 py-2 text-xs whitespace-nowrap">{{ actionLabel(a) }}</td>
                                <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-[11px] font-bold whitespace-nowrap" :class="statusClass[a.status]">{{ statusLabel[a.status] || a.status }}</span></td>
                                <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300 whitespace-pre-line max-w-md">{{ a.summary || '—' }}</td>
                            </tr>
                            <tr v-if="!actions.length"><td colspan="5" class="px-4 py-8 text-center text-gray-400 text-sm">لا إجراءات بعد.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ===== نموذج الرقم ===== -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-4 overflow-y-auto" @click.self="showForm = false">
            <form @submit.prevent="save" class="w-full max-w-3xl mt-[4vh] bg-white dark:bg-gray-900 rounded-2xl shadow-2xl p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ editing ? 'تعديل رقم إدارة' : 'رقم إدارة جديد' }}</h3>
                    <button type="button" @click="showForm = false" class="text-gray-400 hover:text-red-500 text-xl" aria-label="إغلاق">&times;</button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="an-phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">رقم واتساب *</label>
                        <input id="an-phone" v-model="form.phone" dir="ltr" required placeholder="0791234567"
                               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                        <p v-if="form.errors.phone" class="mt-1 text-xs text-red-500">{{ form.errors.phone }}</p>
                    </div>
                    <div>
                        <label for="an-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اسم صاحبه *</label>
                        <input id="an-name" v-model="form.name" required maxlength="100" placeholder="مثال: المدير العام"
                               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label for="an-user" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">حسابه في النظام (اختياري)</label>
                        <select id="an-user" v-model="form.user_id" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm">
                            <option :value="null">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400">لنسبة ما يعدّله إلى حسابه في سجلّ التدقيق.</p>
                    </div>
                    <div class="flex items-center">
                        <label for="an-active" class="flex items-center gap-2 cursor-pointer">
                            <input id="an-active" v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/>
                            <span class="text-sm text-gray-700 dark:text-gray-300">فعّال — أوقفه فوراً إن فُقد الهاتف</span>
                        </label>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <span class="text-sm font-bold text-gray-700 dark:text-gray-200">الأدوات المسموحة</span>
                        <div class="flex gap-1.5 flex-wrap">
                            <button type="button" @click="preset('reports')" class="px-2.5 py-1 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">التقارير فقط</button>
                            <button type="button" @click="preset('all')" class="px-2.5 py-1 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">الكل</button>
                            <button type="button" @click="preset('none')" class="px-2.5 py-1 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">لا شيء</button>
                        </div>
                    </div>
                    <div v-for="(keys, group) in groups" :key="group" class="rounded-xl border border-gray-200 dark:border-gray-700 p-3 space-y-2">
                        <p class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ group }}</p>
                        <label v-for="k in keys" :key="k" :for="'perm-' + k" class="flex items-start gap-3 p-2 rounded-lg cursor-pointer hover:bg-gold-50 dark:hover:bg-gold-900/20">
                            <input :id="'perm-' + k" type="checkbox" :value="k" v-model="form.permissions" class="mt-0.5 w-4 h-4 rounded text-gold-500 shrink-0"/>
                            <span class="min-w-0">
                                <span class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ catalog[k].label }}</span>
                                    <span v-if="catalog[k].write" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">يعدّل — بتأكيد</span>
                                </span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ catalog[k].desc }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.permissions" class="text-xs text-red-500">{{ form.errors.permissions }}</p>
                </div>

                <div>
                    <label for="an-notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ملاحظات</label>
                    <textarea id="an-notes" v-model="form.notes" rows="2" maxlength="1000" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm resize-none"></textarea>
                </div>

                <div class="flex gap-3">
                    <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">حفظ</button>
                    <button type="button" @click="showForm = false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">إلغاء</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
    numbers: { type: Array, default: () => [] },
    catalog: { type: Object, default: () => ({}) },
    users: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    botPhone: { type: String, default: null },
    botReady: { type: Boolean, default: true },
    toolLabels: { type: Object, default: () => ({ tools: {}, actions: {} }) },
});

const showForm = ref(false);
const editing = ref(null);
const form = useForm({ phone: '', name: '', user_id: null, permissions: [], is_active: true, notes: '' });

// الصلاحيات مجمّعة كما في الكتالوج: التقارير، قاعدة المعرفة، إدارة الأدوات
const groups = computed(() => {
    const out = {};
    for (const [k, v] of Object.entries(props.catalog)) (out[v.group] ||= []).push(k);
    return out;
});
const groupedOf = (perms) => {
    const out = {};
    for (const k of Object.keys(props.catalog)) if (perms.includes(k)) (out[props.catalog[k].group] ||= []).push(k);
    return out;
};

const openForm = (n = null) => {
    editing.value = n;
    form.clearErrors();
    form.phone = n ? '+' + n.phone : '';
    form.name = n?.name || '';
    form.user_id = n?.user_id ?? null;
    form.permissions = n ? [...n.permissions] : [];
    form.is_active = n ? n.is_active : true;
    form.notes = n?.notes || '';
    showForm.value = true;
};

const preset = (p) => {
    const keys = Object.keys(props.catalog);
    form.permissions = p === 'all' ? keys : p === 'reports' ? keys.filter(k => k.startsWith('report.')) : [];
};

const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; } };
    if (editing.value) form.put('/whatsapp/admin-numbers/' + editing.value.id, opts);
    else form.post('/whatsapp/admin-numbers', opts);
};

const remove = (n) => {
    if (!confirm(`حذف رقم «${n.name}» من أرقام الإدارة؟ سيُخدم بعدها كأي عميل.`)) return;
    router.delete('/whatsapp/admin-numbers/' + n.id, { preserveScroll: true });
};

// من سجل أدوات المساعد في الخادم (AdminTools::labels)
const TOOL_LABELS = props.toolLabels?.tools || {};
const ACTION_LABELS = props.toolLabels?.actions || {};
const actionLabel = (a) => (TOOL_LABELS[a.tool] || a.tool) + (a.action ? ' · ' + (ACTION_LABELS[a.action] || a.action) : '');
const statusLabel = { done: 'نُفّذ', preview: 'معاينة', denied: 'مرفوض', error: 'خطأ' };
const statusClass = {
    done: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    preview: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    denied: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    error: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
};
</script>
