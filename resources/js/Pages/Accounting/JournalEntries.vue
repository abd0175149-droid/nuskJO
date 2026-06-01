<template>
    <AppLayout>
        <template #header>سجل القيود</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <input v-model="search" type="text" placeholder="بحث برقم القيد أو الوصف..." class="w-72 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500 dark:text-white" @input="debounceSearch" />
                <button @click="showModal=true" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ قيد يدوي</button>
            </div>

            <div class="space-y-4">
                <div v-for="entry in entries.data" :key="entry.id" class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                    <!-- رأس القيد -->
                    <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-xs text-gold-700 font-bold">{{ entry.entry_number }}</span>
                            <span class="text-xs text-gray-400">|</span>
                            <span class="font-mono text-xs text-gray-500" dir="ltr">{{ entry.entry_date?.split('T')[0] }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span v-if="entry.reference_type" class="px-2 py-0.5 rounded text-xs font-bold" :class="refColor(entry.reference_type)">{{ refLabel(entry.reference_type) }}</span>
                            <span class="text-xs text-gray-500">{{ entry.creator?.name }}</span>
                        </div>
                    </div>
                    <div v-if="entry.description" class="px-5 py-2 text-xs text-gray-600 dark:text-gray-400 bg-gray-50/50 dark:bg-gray-800/20 border-b border-gray-100 dark:border-gray-800">
                        {{ entry.description }}
                    </div>
                    <table class="w-full text-sm">
                        <thead><tr class="text-gray-500 dark:text-gray-400 text-xs">
                            <th class="px-5 py-2 text-right">الحساب</th>
                            <th class="px-4 py-2 text-center w-32">مدين</th>
                            <th class="px-4 py-2 text-center w-32">دائن</th>
                            <th class="px-4 py-2 text-right">البيان</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="line in entry.lines" :key="line.id" class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-2 text-right">
                                    <span class="font-mono text-xs text-gold-700 ml-2">{{ line.account?.code }}</span>
                                    <span class="text-xs text-gray-700 dark:text-gray-300">{{ line.account?.name }}</span>
                                </td>
                                <td class="px-4 py-2 text-center font-mono text-xs" :class="parseFloat(line.debit)>0?'text-red-600 font-bold':'text-gray-300'" dir="ltr">{{ parseFloat(line.debit)>0?fmt(line.debit):'—' }}</td>
                                <td class="px-4 py-2 text-center font-mono text-xs" :class="parseFloat(line.credit)>0?'text-green-600 font-bold':'text-gray-300'" dir="ltr">{{ parseFloat(line.credit)>0?fmt(line.credit):'—' }}</td>
                                <td class="px-4 py-2 text-right text-xs text-gray-500 dark:text-gray-400">{{ line.description||'' }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/30 font-bold">
                                <td class="px-5 py-2 text-right text-xs text-gray-600">المجموع</td>
                                <td class="px-4 py-2 text-center font-mono text-xs text-red-600" dir="ltr">{{ fmt(entry.total_debit) }}</td>
                                <td class="px-4 py-2 text-center font-mono text-xs text-green-600" dir="ltr">{{ fmt(entry.total_credit) }}</td>
                                <td class="px-4 py-2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-if="!entries.data?.length" class="text-center py-16 text-gray-400">لا يوجد قيود محاسبية</div>
            </div>

            <!-- Pagination -->
            <div v-if="entries.links?.length > 3" class="flex justify-center gap-1 mt-4">
                <template v-for="(link, i) in entries.links" :key="i">
                    <button v-if="link.url" @click="router.get(link.url, {search}, {preserveState:true})" v-html="link.label" class="px-3 py-1 rounded text-sm" :class="link.active ? 'bg-gold-500 text-black font-bold' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700'"></button>
                    <span v-else v-html="link.label" class="px-3 py-1 text-gray-400 text-sm"></span>
                </template>
            </div>
        </div>

        <!-- Modal قيد يدوي -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showModal=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-3xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-bold">📝 قيد يدوي جديد</h3>
                    <button @click="showModal=false" class="text-gray-400 hover:text-red-500 text-xl">&times;</button>
                </div>
                <form @submit.prevent="submitJournal" class="space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">وصف القيد *</label>
                            <input v-model="jForm.description" type="text" required class="w-full px-4 py-2.5 rounded-xl border text-sm" placeholder="مثال: تسوية أرصدة..." />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">التاريخ</label>
                            <input v-model="jForm.entry_date" type="date" class="w-full px-4 py-2.5 rounded-xl border text-sm" />
                        </div>
                    </div>

                    <!-- سطور القيد -->
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300">سطور القيد</h4>
                            <button type="button" @click="addLine" class="px-3 py-1 text-xs font-bold text-gold-700 bg-gold-100 hover:bg-gold-200 rounded-lg">+ سطر</button>
                        </div>
                        <div v-for="(line, i) in jForm.lines" :key="i"
                             class="grid grid-cols-12 gap-2 items-center p-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/30">
                            <div class="col-span-5">
                                <SearchableSelect v-model="line.account_id" :options="accountOptions" placeholder="اختر الحساب" search-placeholder="ابحث بالكود أو الاسم..." />
                            </div>
                            <div class="col-span-2">
                                <input v-model.number="line.debit" type="number" step="0.001" min="0" placeholder="مدين" dir="ltr"
                                       class="w-full px-3 py-2 rounded-lg border text-xs text-center" :class="line.debit>0?'border-red-300 bg-red-50':''"
                                       @input="line.debit > 0 ? line.credit = 0 : null" />
                            </div>
                            <div class="col-span-2">
                                <input v-model.number="line.credit" type="number" step="0.001" min="0" placeholder="دائن" dir="ltr"
                                       class="w-full px-3 py-2 rounded-lg border text-xs text-center" :class="line.credit>0?'border-green-300 bg-green-50':''"
                                       @input="line.credit > 0 ? line.debit = 0 : null" />
                            </div>
                            <div class="col-span-2">
                                <input v-model="line.description" type="text" placeholder="بيان" class="w-full px-3 py-2 rounded-lg border text-xs" />
                            </div>
                            <div class="col-span-1 text-center">
                                <button v-if="jForm.lines.length > 2" type="button" @click="jForm.lines.splice(i,1)"
                                        class="text-red-400 hover:text-red-600 text-lg">×</button>
                            </div>
                        </div>
                    </div>

                    <!-- ملخص التوازن -->
                    <div class="flex items-center justify-between p-3 rounded-xl border" :class="isBalanced ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'">
                        <div class="flex items-center gap-6 text-sm">
                            <span>مجموع المدين: <strong class="font-mono text-red-600" dir="ltr">{{ fmt(totalDebit) }}</strong></span>
                            <span>مجموع الدائن: <strong class="font-mono text-green-600" dir="ltr">{{ fmt(totalCredit) }}</strong></span>
                        </div>
                        <span v-if="isBalanced" class="text-green-600 font-bold text-xs">✅ متوازن</span>
                        <span v-else class="text-red-600 font-bold text-xs">⚠️ غير متوازن (فرق: {{ fmt(Math.abs(totalDebit - totalCredit)) }})</span>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" :disabled="jForm.processing || !isBalanced" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">✅ تسجيل القيد</button>
                        <button type="button" @click="showModal=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';

const props = defineProps({ entries: Object, filters: Object, leafAccounts: Array });
const accountOptions = computed(() => props.leafAccounts.map(a => ({ value: a.id, label: `${a.code} — ${a.name}` })));
const search = ref(props.filters?.search || '');
const showModal = ref(false);
let t = null;

const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const refLabel = (type) => ({
    receipt: 'سند قبض', disbursement: 'سند صرف', transfer: 'حوالة', invoice: 'فاتورة', 
    violation: 'مخالفة', expense: 'مصروف', manual: 'يدوي',
    advance: 'سلفة', payroll: 'رواتب', reversal: 'عكس قيد', year_closing: 'إقفال'
}[type] || type);
const refColor = (type) => ({
    transfer: 'bg-blue-100 text-blue-700', receipt: 'bg-green-100 text-green-700',
    disbursement: 'bg-green-100 text-green-700',
    invoice: 'bg-purple-100 text-purple-700', violation: 'bg-red-100 text-red-700',
    expense: 'bg-amber-100 text-amber-700', manual: 'bg-gray-100 text-gray-700',
    advance: 'bg-teal-100 text-teal-700', payroll: 'bg-indigo-100 text-indigo-700',
    reversal: 'bg-gray-200 text-gray-600', year_closing: 'bg-indigo-100 text-indigo-700'
}[type] || 'bg-gray-100 text-gray-500');

const debounceSearch = () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/accounting/journal-entries', { search: search.value }, { preserveState: true, replace: true }), 400);
};

// نموذج القيد اليدوي
const jForm = useForm({
    description: '',
    entry_date: new Date().toISOString().split('T')[0],
    lines: [
        { account_id: '', debit: 0, credit: 0, description: '' },
        { account_id: '', debit: 0, credit: 0, description: '' },
    ],
});

const addLine = () => {
    jForm.lines.push({ account_id: '', debit: 0, credit: 0, description: '' });
};

const totalDebit = computed(() => jForm.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0));
const totalCredit = computed(() => jForm.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0));
const isBalanced = computed(() => totalDebit.value > 0 && Math.abs(totalDebit.value - totalCredit.value) < 0.001);

const submitJournal = () => {
    jForm.post('/accounting/journal-entries', {
        onSuccess: () => {
            showModal.value = false;
            jForm.reset();
            jForm.lines = [
                { account_id: '', debit: 0, credit: 0, description: '' },
                { account_id: '', debit: 0, credit: 0, description: '' },
            ];
        },
    });
};
</script>
