<template>
    <AppLayout>
        <template #header>شجرة الحسابات</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <div class="flex items-center justify-between">
                <p class="text-sm text-gray-500">{{ flatCount }} حساب</p>
                <div class="flex items-center gap-2">
                    <a href="/accounting/chart-of-accounts/print" target="_blank" class="px-4 py-2.5 rounded-xl text-sm text-purple-600 border border-purple-200 hover:bg-purple-50">🖨️ طباعة</a>
                    <button @click="openAdd()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ حساب جديد</button>
                </div>
            </div>

            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 p-6">
                <div class="space-y-0.5">
                    <template v-for="account in accounts" :key="account.id">
                        <AccountRow :account="account" :depth="0" @edit="openEdit" @delete="deleteAccount" />
                    </template>
                </div>
                <div v-if="!accounts?.length" class="text-center text-gray-400 py-12">لا يوجد حسابات — قم بتشغيل Seeder</div>
            </div>
        </div>

        <!-- Modal إضافة/تعديل -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showModal=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-bold">{{ editingId ? 'تعديل حساب' : 'حساب جديد' }}</h3>
                    <button @click="showModal=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button>
                </div>
                <form @submit.prevent="submitForm" class="space-y-4">
                    <!-- اختيار الحساب الأب أولاً (مطلوب عند الإضافة) -->
                    <div v-if="!editingId">
                        <label class="block text-sm font-medium mb-1">الحساب الأب *</label>
                        <select v-model="form.parent_id" required @change="onParentChange" class="w-full px-4 py-2.5 rounded-xl border text-sm">
                            <option :value="null" disabled>— اختر الحساب الأب —</option>
                            <option v-for="a in parentOptions" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                        </select>
                    </div>

                    <!-- رقم الحساب (يُولد تلقائياً — للقراءة فقط) -->
                    <div v-if="!editingId && nextCodePreview" class="p-3 rounded-xl border border-gold-200 bg-gold-50 dark:bg-gold-900/10 flex items-center gap-3">
                        <span class="text-sm text-gray-600">رقم الحساب:</span>
                        <span class="font-mono text-lg font-bold text-gold-700" dir="ltr">{{ nextCodePreview }}</span>
                        <span class="text-xs text-gray-400">(يُولد تلقائياً)</span>
                    </div>

                    <!-- عند التعديل: عرض الكود الحالي -->
                    <div v-if="editingId" class="p-3 rounded-xl border border-gray-200 bg-gray-50 flex items-center gap-3">
                        <span class="text-sm text-gray-600">رقم الحساب:</span>
                        <span class="font-mono text-lg font-bold text-gold-700" dir="ltr">{{ form.code }}</span>
                        <span v-if="editingSystem" class="text-xs text-orange-500">(حساب نظام — لا يمكن تغيير الرقم)</span>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium mb-1">اسم الحساب *</label>
                            <input v-model="form.name" type="text" required class="w-full px-4 py-2.5 rounded-xl border text-sm" placeholder="مثال: حساب بنك الأهلي" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">النوع *</label>
                            <select v-model="form.type" required class="w-full px-4 py-2.5 rounded-xl border text-sm">
                                <option value="asset">أصول</option>
                                <option value="liability">التزامات</option>
                                <option value="equity">حقوق ملكية</option>
                                <option value="revenue">إيرادات</option>
                                <option value="expense">مصروفات</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">العملة *</label>
                            <select v-model="form.currency" required class="w-full px-4 py-2.5 rounded-xl border text-sm">
                                <option value="JOD">JOD</option>
                                <option value="SAR">SAR</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">الوصف</label>
                        <textarea v-model="form.description" rows="2" class="w-full px-4 py-2.5 rounded-xl border text-sm resize-none"></textarea>
                    </div>
                    <div v-if="editingId" class="flex items-center gap-2">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input v-model="form.is_active" type="checkbox" class="rounded" />
                            <span>نشط</span>
                        </label>
                    </div>
                    <div class="flex gap-3">
                        <button type="submit" :disabled="form.processing || (!editingId && !nextCodePreview)" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">{{ editingId ? '💾 حفظ' : '✅ إضافة' }}</button>
                        <button type="button" @click="showModal=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, h, defineComponent } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useRealtimeUpdates } from '@/composables/useRealtimeUpdates';
useRealtimeUpdates(['account', 'agent', 'client', 'service']);

const props = defineProps({ accounts: Array });
const showModal = ref(false);
const editingId = ref(null);
const editingSystem = ref(false);
const nextCodePreview = ref('');

const form = useForm({
    code: '', name: '', type: 'asset', parent_id: null,
    currency: 'JOD', description: '', is_active: true,
});

// قائمة مسطحة لكل الحسابات (للحساب الأب)
const flattenAccounts = (accs, result = []) => {
    (accs || []).forEach(a => {
        result.push({ id: a.id, code: a.code, name: a.name, type: a.type });
        if (a.children_recursive?.length) flattenAccounts(a.children_recursive, result);
    });
    return result;
};

const parentOptions = computed(() => flattenAccounts(props.accounts));
const flatCount = computed(() => parentOptions.value.length);

// عند تغيير الحساب الأب — جلب الرقم + ضبط النوع تلقائياً
const onParentChange = () => {
    fetchNextCode();
    const parent = parentOptions.value.find(a => a.id === form.parent_id);
    if (parent) {
        form.type = parent.type;
    }
};

// جلب الرقم التالي من السيرفر
const fetchNextCode = async () => {
    if (!form.parent_id) { nextCodePreview.value = ''; return; }
    try {
        const res = await fetch(`/accounting/accounts/next-code?parent_id=${form.parent_id}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        nextCodePreview.value = data.code || '';
    } catch (e) { nextCodePreview.value = ''; }
};

const openAdd = () => {
    editingId.value = null;
    editingSystem.value = false;
    nextCodePreview.value = '';
    form.reset();
    showModal.value = true;
};

const openEdit = (account) => {
    editingId.value = account.id;
    editingSystem.value = account.is_system;
    form.code = account.code;
    form.name = account.name;
    form.type = account.type;
    form.parent_id = account.parent_id;
    form.currency = account.currency;
    form.description = account.description || '';
    form.is_active = account.is_active;
    showModal.value = true;
};

const submitForm = () => {
    if (editingId.value) {
        form.put('/accounting/accounts/' + editingId.value, {
            preserveState: false,
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; form.reset(); form.clearErrors(); editingId.value = null; }
        });
    } else {
        form.post('/accounting/accounts', {
            preserveState: false,
            preserveScroll: true,
            onSuccess: () => { showModal.value = false; form.reset(); form.clearErrors(); nextCodePreview.value = ''; }
        });
    }
};

const deleteAccount = (account) => {
    if (confirm(`حذف الحساب "${account.name}"؟`)) {
        router.delete('/accounting/accounts/' + account.id);
    }
};

// Component: صف الحساب
const typeColors = {
    asset: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    liability: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300',
    equity: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
    revenue: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
    expense: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
};
const typeLabels = { asset: 'أصول', liability: 'التزامات', equity: 'ملكية', revenue: 'إيرادات', expense: 'مصروفات' };

const depthBg = [
    'bg-gray-100/80 dark:bg-gray-800/60',
    'bg-gray-50/60 dark:bg-gray-800/30',
    'bg-white dark:bg-gray-900/40',
    'bg-white dark:bg-transparent',
];

const AccountRow = defineComponent({
    name: 'AccountRow',
    props: { account: Object, depth: Number },
    emits: ['edit', 'delete'],
    setup(props, { emit }) {
        const collapsed = ref(false);
        const a = props.account;
        const hasChildren = a.children_recursive?.length > 0;
        const isLeaf = !hasChildren;
        const indent = props.depth * 28;
        const bg = depthBg[Math.min(props.depth, depthBg.length - 1)];

        // حساب المجموع التراكمي (مجموع كل الأطفال بشكل عودي)
        const calcTotal = (acc) => {
            if (!acc.children_recursive?.length) return parseFloat(acc.balance || 0);
            return acc.children_recursive.reduce((sum, c) => sum + calcTotal(c), 0);
        };
        const totalBalance = computed(() => isLeaf ? parseFloat(a.balance || 0) : calcTotal(a));

        return () => h('div', {}, [
            h('div', {
                class: `group flex items-center gap-2 py-2 px-3 transition-all duration-150 cursor-pointer ${bg} ${
                    props.depth === 0
                        ? 'border-b-2 border-gray-300 dark:border-gray-600 mb-1'
                        : 'border-b border-gray-200/70 dark:border-gray-700/50'
                } ${isLeaf ? 'hover:bg-gold-50/50 dark:hover:bg-gold-900/10' : 'hover:bg-gray-200/50 dark:hover:bg-gray-700/30'}`,
                style: { paddingRight: `${14 + indent}px` },
                onClick: () => { if (hasChildren) collapsed.value = !collapsed.value; },
            }, [
                h('span', {
                    class: `w-5 h-5 flex items-center justify-center text-xs rounded transition-transform duration-200 ${hasChildren ? (collapsed.value ? 'rotate-[-90deg]' : '') : 'text-gray-300 dark:text-gray-600'}`,
                }, hasChildren ? '▾' : '·'),
                h('span', { class: 'text-base' }, isLeaf ? '📄' : (collapsed.value ? '📁' : '📂')),
                h('span', {
                    class: `font-mono text-xs font-bold min-w-[55px] ${props.depth === 0 ? 'text-gold-600 dark:text-gold-400 text-sm' : 'text-gold-700/70 dark:text-gold-500/70'}`,
                    dir: 'ltr',
                }, a.code),
                h('span', {
                    class: `flex-1 text-sm ${
                        props.depth === 0 ? 'font-extrabold text-gray-900 dark:text-white text-base' :
                        props.depth === 1 ? 'font-bold text-gray-800 dark:text-gray-200' :
                        'font-medium text-gray-700 dark:text-gray-300'
                    }`,
                }, a.name),
                h('span', { class: `px-2 py-0.5 rounded-md text-[10px] font-bold ${typeColors[a.type] || ''}` }, typeLabels[a.type]),
                h('span', {
                    class: `font-mono text-xs font-bold min-w-[70px] text-left ${
                        totalBalance.value === 0 ? 'text-gray-400 dark:text-gray-500' :
                        ['liability', 'equity'].includes(a.type)
                            ? (totalBalance.value > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400')
                            : (totalBalance.value > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400')
                    }`,
                    dir: 'ltr',
                }, Number(Math.abs(totalBalance.value)).toLocaleString('en', { minimumFractionDigits: 3 })),
                h('span', { class: 'text-[10px] text-gray-400 dark:text-gray-500 font-mono min-w-[28px]' }, a.currency),
                h('div', { class: 'hidden group-hover:flex items-center gap-1 mr-1' }, [
                    h('a', {
                        class: 'px-1.5 py-0.5 text-xs text-purple-600 hover:bg-purple-100 dark:hover:bg-purple-900/30 rounded',
                        href: '/accounting/accounts/' + a.id + '/details',
                        title: 'كشف حساب',
                        onClick: (e) => e.stopPropagation(),
                    }, '📊'),
                    h('button', {
                        class: 'px-1.5 py-0.5 text-xs text-blue-600 hover:bg-blue-100 dark:hover:bg-blue-900/30 rounded',
                        onClick: (e) => { e.stopPropagation(); emit('edit', a); },
                    }, '✏️'),
                    !a.is_system ? h('button', {
                        class: 'px-1.5 py-0.5 text-xs text-red-600 hover:bg-red-100 dark:hover:bg-red-900/30 rounded',
                        onClick: (e) => { e.stopPropagation(); emit('delete', a); },
                    }, '🗑️') : null,
                ]),
            ]),
            ...(hasChildren && !collapsed.value ? a.children_recursive.map(child =>
                h(AccountRow, { account: child, depth: props.depth + 1, key: child.id, onEdit: (ac) => emit('edit', ac), onDelete: (ac) => emit('delete', ac) })
            ) : []),
        ]);
    },
});
</script>
