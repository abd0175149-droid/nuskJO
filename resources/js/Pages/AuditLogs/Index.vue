<template>
    <AppLayout>
        <template #header>سجل التدقيق</template>
        <div class="space-y-5" dir="rtl">
            <!-- التبويبان -->
            <div class="flex items-center gap-2 border-b border-gray-200 dark:border-gray-700">
                <Link href="/audit-logs" class="px-4 py-2 text-sm font-bold -mb-px border-b-2"
                      :class="tab === 'general' ? 'border-gold-500 text-gold-700 dark:text-gold-400' : 'border-transparent text-gray-500 dark:text-gray-400'">السجل العام</Link>
                <Link href="/audit-logs?tab=admin" class="px-4 py-2 text-sm font-bold -mb-px border-b-2"
                      :class="tab === 'admin' ? 'border-gold-500 text-gold-700 dark:text-gold-400' : 'border-transparent text-gray-500 dark:text-gray-400'">إجراءات أرقام الإدارة</Link>
            </div>

            <template v-if="tab === 'general'">
                <!-- الفلاتر -->
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                    <select v-model="f.action" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white">
                        <option value="">كل الأفعال</option>
                        <optgroup v-for="(items, cat) in actionsByCategory" :key="cat" :label="CATEGORY[cat] || cat">
                            <option v-for="a in items" :key="a.value" :value="a.value">{{ a.label }}</option>
                        </optgroup>
                    </select>
                    <select v-model="f.severity" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white">
                        <option value="">كل الخطورات</option>
                        <option value="info">معلومة</option><option value="warn">تحذير</option><option value="error">خطأ</option>
                    </select>
                    <select v-model="f.actor_type" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white">
                        <option value="">كل الفاعلين</option>
                        <option v-for="(label, k) in ACTOR" :key="k" :value="k">{{ label }}</option>
                    </select>
                    <select v-model="f.user_id" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white">
                        <option value="">كل الموظفين</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-model="f.entity_type" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white">
                        <option value="">كل الكيانات</option>
                        <option v-for="t in entityTypes" :key="t" :value="t">{{ entityLabel(t) }}</option>
                    </select>
                    <input v-model="f.entity_id" type="number" min="1" placeholder="رقم الكيان" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white"/>
                    <input v-model="f.request_id" type="text" placeholder="معرّف الطلب" dir="ltr" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white font-mono text-xs"/>
                    <div class="flex items-center gap-2">
                        <input v-model="f.from" type="date" class="flex-1 min-w-0 px-2 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white"/>
                        <input v-model="f.to" type="date" class="flex-1 min-w-0 px-2 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white"/>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-4 flex flex-wrap items-center gap-2">
                        <button @click="apply" class="px-5 py-2 rounded-xl font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400">تطبيق</button>
                        <button @click="clear" class="px-4 py-2 rounded-xl text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">مسح</button>
                        <a v-if="canExport" :href="exportUrl" class="ms-auto px-4 py-2 rounded-xl font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200"
                           :title="`حتى ${exportMax.toLocaleString('en')} صف؛ ما زاد يُقتطع`">⬇ تصدير CSV</a>
                    </div>
                </div>

                <!-- الجدول -->
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm responsive-table">
                            <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400 text-xs">
                                <th class="px-3 py-2 text-right font-bold">الوقت</th>
                                <th class="px-3 py-2 text-right font-bold">الخطورة</th>
                                <th class="px-3 py-2 text-right font-bold">الفعل</th>
                                <th class="px-3 py-2 text-right font-bold">الفاعل</th>
                                <th class="px-3 py-2 text-right font-bold">الكيان</th>
                                <th class="px-3 py-2 text-right font-bold hide-mobile">IP</th>
                                <th class="px-3 py-2"></th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="row in logs.data" :key="row.id" class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30 align-top">
                                    <td data-label="الوقت" class="px-3 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ when(row.created_at) }}</td>
                                    <td data-label="الخطورة" class="px-3 py-2"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="SEVERITY[row.severity]?.cls">{{ SEVERITY[row.severity]?.label || row.severity }}</span></td>
                                    <td data-label="الفعل" class="px-3 py-2 font-bold text-gray-800 dark:text-gray-200">
                                        {{ row.action_label }}
                                        <p v-if="row.summary" class="font-normal text-xs text-gray-400 dark:text-gray-500 font-mono break-all" dir="ltr">{{ row.summary }}</p>
                                    </td>
                                    <td data-label="الفاعل" class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ row.actor_label }}</td>
                                    <td data-label="الكيان" class="px-3 py-2 text-gray-700 dark:text-gray-300">
                                        {{ entityLabel(row.entity_type) }}<span v-if="row.entity_id" class="font-mono text-xs text-gray-400" dir="ltr"> #{{ row.entity_id }}</span>
                                        <p v-if="row.entity_label" class="text-xs text-gray-500 dark:text-gray-400">{{ row.entity_label }}</p>
                                    </td>
                                    <td data-label="IP" class="px-3 py-2 font-mono text-xs text-gray-500 dark:text-gray-400 hide-mobile" dir="ltr"
                                        title="العنوان كما وصل للخادم؛ قد لا يكون عنوان المستخدم الحقيقي خلف وسيط">{{ row.ip_address || '—' }}</td>
                                    <td class="px-3 py-2 text-center actions-cell"><button @click="openDetails(row)" class="px-2 py-1 text-xs text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg">تفاصيل</button></td>
                                </tr>
                                <tr v-if="!logs.data?.length"><td colspan="7" class="px-5 py-12 text-center text-gray-400">لا صفوف تطابق الفلاتر</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <Pager :links="logs.links"/>
            </template>

            <template v-else>
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 flex flex-wrap gap-3 text-sm">
                    <select v-model="a.admin_number_id" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white">
                        <option value="">كل الأرقام</option>
                        <option v-for="n in adminNumbers" :key="n.id" :value="n.id">{{ n.name }}</option>
                    </select>
                    <select v-model="a.status" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white">
                        <option value="">كل الحالات</option>
                        <option v-for="s in ['preview', 'done', 'denied', 'error']" :key="s" :value="s">{{ s }}</option>
                    </select>
                    <input v-model="a.tool" type="text" placeholder="الأداة" dir="ltr" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 dark:text-white font-mono text-xs"/>
                    <button @click="applyAdmin" class="px-5 py-2 rounded-xl font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400">تطبيق</button>
                </div>
                <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm responsive-table">
                            <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-400 text-xs">
                                <th class="px-3 py-2 text-right font-bold">الوقت</th><th class="px-3 py-2 text-right font-bold">الرقم</th>
                                <th class="px-3 py-2 text-right font-bold">الأداة</th><th class="px-3 py-2 text-right font-bold">الحالة</th>
                                <th class="px-3 py-2 text-right font-bold">الملخص</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="row in adminActions.data" :key="row.id" class="border-t border-gray-100 dark:border-gray-700/50 align-top">
                                    <td data-label="الوقت" class="px-3 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ when(row.created_at) }}</td>
                                    <td data-label="الرقم" class="px-3 py-2 text-gray-700 dark:text-gray-300">👔 {{ row.admin || '—' }}</td>
                                    <td data-label="الأداة" class="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-300" dir="ltr">{{ row.tool }}<span v-if="row.action">:{{ row.action }}</span></td>
                                    <td data-label="الحالة" class="px-3 py-2"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="ADMIN_STATUS[row.status] || 'bg-gray-100 text-gray-700'">{{ row.status }}</span></td>
                                    <td data-label="الملخص" class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ row.summary || '—' }}</td>
                                </tr>
                                <tr v-if="!adminActions.data?.length"><td colspan="5" class="px-5 py-12 text-center text-gray-400">لا إجراءات</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <Pager :links="adminActions.links"/>
            </template>
        </div>

        <!-- نافذة التفاصيل -->
        <div v-if="details" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="details = null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl mx-4 p-6 max-h-[90vh] overflow-y-auto" dir="rtl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ details.action_label }}</h3>
                    <button @click="details = null" class="text-gray-400 hover:text-red-500 text-xl">&times;</button>
                </div>
                <dl class="grid grid-cols-2 gap-3 text-sm mb-4">
                    <div><dt class="text-gray-400">الوقت</dt><dd dir="ltr" class="text-right">{{ when(details.created_at) }}</dd></div>
                    <div><dt class="text-gray-400">الفاعل</dt><dd>{{ details.actor_label }}</dd></div>
                    <div><dt class="text-gray-400">الطلب</dt><dd dir="ltr" class="text-right font-mono text-xs">{{ details.method }} /{{ details.path }}</dd></div>
                    <div><dt class="text-gray-400">معرّف الطلب</dt><dd dir="ltr" class="text-right font-mono text-xs">{{ details.request_id || '—' }}</dd></div>
                </dl>
                <pre v-if="details.changes" class="text-xs bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-200 rounded-xl p-3 overflow-x-auto" dir="ltr">{{ JSON.stringify(details.changes, null, 2) }}</pre>
                <p v-else-if="loadingDetails" class="text-sm text-gray-400">جارٍ التحميل…</p>
                <p v-else class="text-sm text-gray-400">لا تفاصيل إضافية.</p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, reactive, ref, h } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { entityLabel, when } from '@/utils/audit.js';

const props = defineProps({
    tab: String,
    filters: Object,
    logs: Object,
    adminActions: Object,
    actions: Array,
    entityTypes: Array,
    users: Array,
    adminNumbers: Array,
    canExport: Boolean,
    exportMax: Number,
});

const SEVERITY = {
    info: { label: 'معلومة', cls: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' },
    warn: { label: 'تحذير', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' },
    error: { label: 'خطأ', cls: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' },
};
const ACTOR = { user: '👤 موظف', bot: '🤖 البوت', admin_number: '👔 رقم إدارة', meta: 'ميتا', system: 'النظام' };
const CATEGORY = { money: 'المال', access: 'الدخول والصلاحيات', config: 'الإعدادات', whatsapp: 'الواتساب', agent: 'البوت', meta: 'ميتا', noise: 'متكرر', system: 'النظام' };
const ADMIN_STATUS = {
    done: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    denied: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    error: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
};

const actionsByCategory = computed(() => props.actions.reduce((acc, a) => ((acc[a.category] ||= []).push(a), acc), {}));

const initial = () => ({
    action: props.filters.action?.[0] || '', severity: props.filters.severity || '', actor_type: props.filters.actor_type || '',
    user_id: props.filters.user_id || '', entity_type: props.filters.entity_type || '', entity_id: props.filters.entity_id || '',
    request_id: props.filters.request_id || '', from: props.filters.from || '', to: props.filters.to || '',
});
const f = reactive(initial());
const params = () => {
    const p = Object.fromEntries(Object.entries(f).filter(([k, v]) => v !== '' && v !== null && k !== 'action'));
    if (f.action) p.action = [f.action];
    return p;
};
const apply = () => router.get('/audit-logs', params(), { preserveState: true, replace: true });
const clear = () => { Object.assign(f, Object.fromEntries(Object.keys(f).map(k => [k, '']))); apply(); };
const exportUrl = computed(() => {
    const q = new URLSearchParams();
    Object.entries(params()).forEach(([k, v]) => Array.isArray(v) ? v.forEach(x => q.append(k + '[]', x)) : q.append(k, v));
    return '/audit-logs/export' + (q.toString() ? '?' + q : '');
});

const query = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
const a = reactive({ admin_number_id: query.get('admin_number_id') || '', status: query.get('status') || '', tool: query.get('tool') || '' });
const applyAdmin = () => router.get('/audit-logs', { tab: 'admin', ...Object.fromEntries(Object.entries(a).filter(([, v]) => v !== '')) }, { preserveState: true, replace: true });

const details = ref(null);
const loadingDetails = ref(false);
const openDetails = async (row) => {
    details.value = { ...row };
    loadingDetails.value = true;
    try {
        const res = await fetch('/api/audit-logs/' + row.id, { headers: { Accept: 'application/json' } });
        if (res.ok) details.value = await res.json();
    } finally {
        loadingDetails.value = false;
    }
};

// ترقيم بنمط links المستعمل في الصفحات الأخرى
const Pager = (p) => (p.links?.length > 3)
    ? h('div', { class: 'flex justify-center flex-wrap gap-1 mt-4' }, p.links.map((link, i) => link.url
        ? h('button', { key: i, innerHTML: link.label, onClick: () => router.get(link.url, {}, { preserveState: true }),
            class: 'px-3 py-1 rounded text-sm ' + (link.active ? 'bg-gold-500 text-black font-bold' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700') })
        : h('span', { key: i, innerHTML: link.label, class: 'px-3 py-1 text-gray-400 text-sm' })))
    : null;
Pager.props = ['links'];
</script>
