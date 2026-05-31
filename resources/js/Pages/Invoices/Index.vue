<template>
    <AppLayout>
        <template #header>المطالبات</template>
        <div class="space-y-6">
            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

            <!-- Controls -->
            <div class="flex flex-wrap items-center justify-between gap-4 filter-bar">
                <div class="flex items-center gap-3 flex-wrap">
                    <input v-model="search" type="text" placeholder="بحث بالرقم أو العميل..." class="w-64 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:outline-none focus:ring-2 focus:ring-gold-500" @input="debounceSearch"/>
                    <select v-model="statusFilter" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm" @change="applyFilter">
                        <option value="">كل الحالات</option><option value="pending">معلقة</option><option value="approved">معتمدة</option><option value="rejected">مرفوضة</option><option value="editing">تحت التعديل</option>
                    </select>
                </div>
                <button v-if="can('invoices.create')" @click="openPOS()" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md hover:shadow-gold-500/25 w-full sm:w-auto">🧾 فاتورة جديدة</button>
            </div>

            <!-- Table -->
            <div class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="w-full text-sm responsive-table">
                    <thead><tr class="bg-gray-50 text-gray-600">
                        <th class="px-4 py-3 text-right font-bold">الرقم</th>
                        <th class="px-4 py-3 text-right font-bold">العميل</th>
                        <th class="px-4 py-3 text-right font-bold hide-mobile">تاريخ الرحلة</th>
                        <th class="px-4 py-3 text-right font-bold">التكلفة JOD</th>
                        <th class="px-4 py-3 text-right font-bold">البيع JOD</th>
                        <th class="px-4 py-3 text-right font-bold hide-mobile">الربح JOD</th>
                        <th class="px-4 py-3 text-right font-bold">الحالة</th>
                        <th class="px-4 py-3 text-right font-bold hide-mobile">بواسطة</th>
                        <th class="px-4 py-3 text-center font-bold">إجراءات</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="inv in invoices.data" :key="inv.id" :data-row-id="inv.id"
                            class="border-t border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/30"
                            :class="{ 'row-glow': isHighlighted(inv.id) }">
                            <td data-label="الرقم" class="px-4 py-3 text-right font-mono text-xs text-gold-700">{{ inv.invoice_number }}</td>
                            <td data-label="العميل" class="px-4 py-3 text-right text-xs font-medium">{{ inv.client?.name||'—' }}</td>
                            <td data-label="الرحلة" class="px-4 py-3 text-right font-mono text-xs text-gray-500 hide-mobile" dir="ltr">{{ inv.trip_date?.split('T')[0] || '—' }}</td>
                            <td data-label="التكلفة" class="px-4 py-3 text-right font-bold font-mono text-xs" dir="ltr">{{ Number(inv.total_cost_jod||0).toLocaleString('en',{minimumFractionDigits:3}) }}</td>
                            <td data-label="البيع" class="px-4 py-3 text-right font-bold font-mono text-xs text-blue-600" dir="ltr">{{ Number(inv.total_sell_jod||inv.total_jod||0).toLocaleString('en',{minimumFractionDigits:3}) }}</td>
                            <td data-label="الربح" class="px-4 py-3 text-right font-bold font-mono text-xs hide-mobile" :class="Number(inv.profit_jod)>=0?'text-green-600':'text-red-600'" dir="ltr">{{ Number(inv.profit_jod||0).toLocaleString('en',{minimumFractionDigits:3}) }}</td>
                            <td data-label="الحالة" class="px-4 py-3 text-right"><span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="{'bg-yellow-100 text-yellow-700':inv.status==='pending','bg-green-100 text-green-700':inv.status==='approved','bg-red-100 text-red-700':inv.status==='rejected','bg-blue-100 text-blue-700':inv.status==='editing'}">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة',editing:'تحت التعديل'}[inv.status] }}</span></td>
                            <td data-label="بواسطة" class="px-4 py-3 text-right text-xs text-gray-500 hide-mobile"><div>📝 {{ inv.creator?.name || '—' }}</div><div v-if="inv.status !== 'pending'" class="mt-0.5">{{ inv.status === 'approved' ? '✅' : '❌' }} {{ inv.approver?.name || '—' }}</div></td>
                            <td data-label="" class="px-4 py-3 text-center whitespace-nowrap actions-cell">
                                <a :href="'/invoices/'+inv.id+'/print'" target="_blank" class="px-2 py-1 text-xs text-purple-600 hover:bg-purple-50 rounded-lg btn-mobile-sm">🖨️</a>
                                <button @click="viewInv(inv)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg btn-mobile-sm">👁️</button>
                                <button v-if="inv.status==='pending' && can('invoices.approve')" @click="approveInv(inv)" class="px-2 py-1 text-xs text-green-600 hover:bg-green-50 rounded-lg btn-mobile-sm">✅</button>
                                <button v-if="inv.status==='pending' && can('invoices.reject')" @click="rejectInv(inv)" class="px-2 py-1 text-xs text-orange-600 hover:bg-orange-50 rounded-lg btn-mobile-sm">❌</button>
                                <button v-if="inv.status!=='approved' && can('invoices.delete')" @click="delInv(inv)" class="px-2 py-1 text-xs text-red-600 hover:bg-red-50 rounded-lg btn-mobile-sm">🗑️</button>
                                <button v-if="inv.status==='approved' && can('invoices.edit_approved')" @click="startEditInv(inv)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg btn-mobile-sm">✏️ تعديل</button>
                                <button v-if="inv.status==='editing'" @click="openPOS(inv)" class="px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 rounded-lg font-bold btn-mobile-sm">📝 تعديل الفاتورة</button>
                            </td>
                        </tr>
                        <tr v-if="!invoices.data?.length"><td colspan="9" class="px-5 py-12 text-center text-gray-400">لا يوجد فواتير</td></tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- POS Modal -->
        <div v-if="showPOS" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showPOS=false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-4xl mx-4 p-6 max-h-[95vh] overflow-y-auto modal-responsive" style="overflow: visible auto;">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ editingInvoiceId ? '✏️ تعديل فاتورة' : '🧾 فاتورة مبيعات جديدة' }}</h3>
                    <button @click="showPOS=false" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button>
                </div>
                <div class="space-y-5">
                    <!-- Row 1: Client + Phone + Trip Date -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">العميل *</label>
                            <SearchableSelect v-model="pos.client_id" :options="clientOptions" placeholder="اختر العميل" search-placeholder="ابحث عن عميل..." @change="onClientSelect" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">هاتف العميل</label>
                            <input v-model="pos.client_phone" type="text" dir="ltr" placeholder="07XXXXXXXX" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm"/>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">تاريخ الرحلة</label>
                            <input v-model="pos.trip_date" type="date" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm"/>
                        </div>
                    </div>

                    <!-- Add Items -->
                    <div class="border border-gray-200 rounded-xl p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="font-bold text-sm text-gray-700">بنود التكاليف</h4>
                            <button @click="addItem" type="button" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-blue-50 text-blue-700 hover:bg-blue-100">+ إضافة بند</button>
                        </div>

                        <!-- Items Table -->
                        <div v-if="pos.items.length" style="overflow: visible;">
                            <table class="w-full text-xs">
                                <thead><tr class="bg-gray-50 text-gray-500">
                                    <th class="px-3 py-2 text-right w-1/5">الوكيل *</th>
                                    <th class="px-3 py-2 text-right w-1/5">الخدمة</th>
                                    <th class="px-3 py-2 text-center w-16">العدد</th>
                                    <th class="px-3 py-2 text-center w-28">التكلفة JOD</th>
                                    <th class="px-3 py-2 text-right w-1/5">البيان</th>
                                    <th class="px-3 py-2 text-center w-24">إجمالي JOD</th>
                                    <th class="px-3 py-2 text-center w-10"></th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(item, idx) in pos.items" :key="idx" class="border-t border-gray-100">
                                        <td class="px-3 py-2"><SearchableSelect v-model="item.agent_id" :options="agentOptions" placeholder="الوكيل" search-placeholder="ابحث..." :drop-up="idx > 2" /></td>
                                        <td class="px-3 py-2"><SearchableSelect v-model="item.service_id" :options="serviceOptions" placeholder="اختر خدمة" search-placeholder="ابحث..." :drop-up="idx > 2" @change="onServiceSelect(idx)" /></td>
                                        <td class="px-3 py-2"><input v-model.number="item.quantity" type="number" min="1" class="w-full px-2 py-1.5 rounded border border-gray-200 text-xs text-center" dir="ltr"/></td>
                                        <td class="px-3 py-2"><input v-model.number="item.unit_price_jod" type="number" step="0.001" class="w-full px-2 py-1.5 rounded border border-gray-200 text-xs font-mono text-center" dir="ltr"/></td>
                                        <td class="px-3 py-2"><input v-model="item.statement" type="text" placeholder="بيان..." class="w-full px-2 py-1.5 rounded border border-gray-200 text-xs"/></td>
                                        <td class="px-3 py-2 font-mono font-bold text-blue-600 text-center" dir="ltr">{{ (item.quantity * item.unit_price_jod).toFixed(3) }}</td>
                                        <td class="px-3 py-2 text-center"><button @click="pos.items.splice(idx,1)" class="text-red-400 hover:text-red-600">✕</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-center text-gray-400 text-xs py-6">أضف بنوداً للفاتورة</p>
                    </div>

                    <!-- Sell Price + Totals -->
                    <div v-if="pos.items.length" class="bg-gray-50 rounded-xl p-4">
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                            <div>
                                <span class="text-gray-500">إجمالي التكلفة:</span>
                                <p class="font-bold font-mono text-lg" dir="ltr">{{ totalCostJod.toFixed(3) }} JOD</p>
                            </div>
                            <div>
                                <label class="text-gray-500">سعر البيع (JOD) *</label>
                                <input v-model.number="pos.sell_price_jod" type="number" step="0.001" min="0" dir="ltr" class="w-full px-3 py-2 rounded-xl border border-gray-300 text-sm font-mono font-bold text-blue-600 mt-1 focus:ring-2 focus:ring-gold-500 focus:outline-none"/>
                            </div>
                            <div>
                                <span class="text-gray-500">الربح:</span>
                                <p class="font-mono text-lg font-bold" :class="profitJod >= 0 ? 'text-green-600' : 'text-red-600'" dir="ltr">{{ profitJod.toFixed(3) }} JOD {{ profitJod >= 0 ? '✅' : '⚠️' }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Notes + Submit -->
                    <div><label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label><textarea v-model="pos.notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none resize-none"></textarea></div>
                    <div class="flex gap-3">
                        <button @click="submitPOS" :disabled="submitting||!pos.client_id||!pos.items.length||!allItemsHaveAgent" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">{{ editingInvoiceId ? '📤 حفظ التعديلات' : '🧾 إرسال للاعتماد' }}</button>
                        <button @click="showPOS=false" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- View Modal -->
        <div v-if="viewTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="viewTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-3xl mx-4 p-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-4"><h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">تفاصيل الفاتورة</h3><button @click="viewTarget=null" class="text-gray-400 dark:text-gray-500 hover:text-red-500 text-xl">&times;</button></div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm mb-4">
                    <div><span class="text-gray-400">الرقم:</span><p class="font-mono text-gold-700">{{ viewTarget.invoice_number }}</p></div>
                    <div><span class="text-gray-400">التاريخ:</span><p>{{ viewTarget.invoice_date?.split('T')[0] }}</p></div>
                    <div><span class="text-gray-400">العميل:</span><p>{{ viewTarget.client?.name }}</p></div>
                    <div><span class="text-gray-400">هاتف:</span><p dir="ltr">{{ viewTarget.client_phone || '—' }}</p></div>
                    <div><span class="text-gray-400">تاريخ الرحلة:</span><p>{{ viewTarget.trip_date?.split('T')[0] || '—' }}</p></div>
                    <div><span class="text-gray-400">التكلفة JOD:</span><p class="font-bold font-mono" dir="ltr">{{ Number(viewTarget.total_cost_jod||0).toFixed(3) }}</p></div>
                    <div><span class="text-gray-400">البيع JOD:</span><p class="font-bold font-mono text-blue-600" dir="ltr">{{ Number(viewTarget.total_sell_jod||viewTarget.total_jod||0).toFixed(3) }}</p></div>
                    <div><span class="text-gray-400">الربح JOD:</span><p class="font-bold font-mono" :class="Number(viewTarget.profit_jod)>=0?'text-green-600':'text-red-600'" dir="ltr">{{ Number(viewTarget.profit_jod||0).toFixed(3) }}</p></div>
                </div>
                <!-- Items -->
                <div v-if="viewDetails?.items?.length" class="mt-4">
                    <h4 class="text-sm font-bold mb-2">البنود:</h4>
                    <table class="w-full text-xs">
                        <thead><tr class="bg-gray-50"><th class="px-3 py-2 text-right">الوكيل</th><th class="px-3 py-2 text-right">الوصف</th><th class="px-3 py-2 text-right">العدد</th><th class="px-3 py-2 text-right">التكلفة JOD</th><th class="px-3 py-2 text-right">البيان</th></tr></thead>
                        <tbody><tr v-for="item in viewDetails.items" :key="item.id" class="border-t"><td class="px-3 py-2">{{ item.agent?.name || '—' }}</td><td class="px-3 py-2">{{ item.description }}</td><td class="px-3 py-2 text-center">{{ item.quantity }}</td><td class="px-3 py-2 font-mono" dir="ltr">{{ Number(item.total_cost_jod||0).toFixed(3) }}</td><td class="px-3 py-2 text-gray-500">{{ item.statement || '—' }}</td></tr></tbody>
                    </table>
                </div>
                <div v-if="viewTarget.notes" class="text-sm mt-4"><span class="text-gray-400">ملاحظات:</span><p>{{ viewTarget.notes }}</p></div>
            </div>
        </div>

        <!-- Delete Modal -->
        <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="deleteTarget=null">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 text-center">
                <div class="text-5xl mb-4">⚠️</div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">تأكيد الحذف</h3>
                <p class="text-sm text-gray-500 mb-6">حذف الفاتورة <strong>{{ deleteTarget.invoice_number }}</strong>؟</p>
                <div class="flex gap-3 justify-center">
                    <button @click="router.delete('/invoices/'+deleteTarget.id,{preserveScroll:true,onSuccess:()=>{deleteTarget=null}})" class="px-6 py-2.5 rounded-xl font-bold text-sm text-white bg-red-500 hover:bg-red-600">🗑️ حذف</button>
                    <button @click="deleteTarget=null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100">إلغاء</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useHighlight } from '@/composables/useHighlight';
const { can } = usePermissions();
const { isHighlighted } = useHighlight();
import SearchableSelect from '@/Components/SearchableSelect.vue';

const props = defineProps({ invoices: Object, filters: Object, agents: Array, clients: Array, services: Array, exchangeRate: Number });
const search = ref(props.filters?.search||'');
const statusFilter = ref(props.filters?.status||'');
const showPOS = ref(false);
const viewTarget = ref(null);
const viewDetails = ref(null);
const deleteTarget = ref(null);
const submitting = ref(false);
let t = null;

const pos = reactive({
    client_id: '',
    client_phone: '',
    trip_date: '',
    sell_price_jod: 0,
    discount_jod: 0,
    notes: '',
    items: [],
});

// خيارات القوائم
const agentOptions = computed(() => props.agents.map(a => ({ value: a.id, label: `${a.name} (${a.code})` })));
const clientOptions = computed(() => props.clients.map(c => ({ value: c.id, label: `${c.name} (${c.code})` })));
const serviceOptions = computed(() => props.services.map(s => ({ value: s.id, label: s.name })));

// حسابات الإجماليات
const totalCostJod = computed(() => pos.items.reduce((s, i) => s + (i.quantity||0) * (i.unit_price_jod||0), 0));
const profitJod = computed(() => (pos.sell_price_jod || 0) - totalCostJod.value);

const allItemsHaveAgent = computed(() => pos.items.every(i => i.agent_id));

const editingInvoiceId = ref(null);

const onClientSelect = () => {
    const client = props.clients.find(c => c.id == pos.client_id);
    if (client && client.phone) pos.client_phone = client.phone;
};

const openPOS = async (inv = null) => {
    if (inv && inv.id) {
        editingInvoiceId.value = inv.id;
        try {
            const res = await fetch('/api/invoices/' + inv.id + '/details');
            const data = await res.json();
            pos.client_id = data.client_id;
            pos.client_phone = data.client_phone || '';
            pos.trip_date = data.trip_date?.split('T')[0] || '';
            pos.sell_price_jod = parseFloat(data.total_sell_jod) || 0;
            pos.discount_jod = parseFloat(data.discount_jod) || 0;
            pos.notes = data.notes || '';
            pos.items = (data.items || []).map(item => ({
                agent_id: item.agent_id || '',
                service_id: item.service_id || '',
                description: item.description,
                statement: item.statement || '',
                quantity: item.quantity,
                unit_price_jod: parseFloat(item.unit_price_jod) || parseFloat(item.unit_price_sar) * 0.19 || 0,
            }));
        } catch (e) {
            console.error('Error loading invoice:', e);
            pos.client_id = inv.client_id || '';
            pos.client_phone = '';
            pos.trip_date = '';
            pos.sell_price_jod = 0;
            pos.discount_jod = 0;
            pos.notes = '';
            pos.items = [];
        }
    } else {
        editingInvoiceId.value = null;
        pos.client_id = '';
        pos.client_phone = '';
        pos.trip_date = '';
        pos.sell_price_jod = 0;
        pos.discount_jod = 0;
        pos.notes = '';
        pos.items = [];
    }
    showPOS.value = true;
};

const addItem = () => {
    pos.items.push({ agent_id: '', service_id: '', description: '', statement: '', quantity: 1, unit_price_jod: 0 });
};

const onServiceSelect = (idx) => {
    const s = props.services.find(x => x.id == pos.items[idx].service_id);
    if (s) {
        pos.items[idx].description = s.name;
        pos.items[idx].unit_price_jod = parseFloat(s.default_price_jod) || 0;
    }
};

const submitPOS = () => {
    submitting.value = true;
    const payload = {
        client_id: pos.client_id,
        client_phone: pos.client_phone || null,
        trip_date: pos.trip_date || null,
        sell_price_jod: pos.sell_price_jod || 0,
        discount_jod: pos.discount_jod || 0,
        notes: pos.notes,
        items: pos.items.map(i => ({
            agent_id: i.agent_id,
            description: i.description,
            statement: i.statement || null,
            quantity: i.quantity,
            unit_price_jod: i.unit_price_jod,
            service_id: i.service_id || null,
        })),
    };

    if (editingInvoiceId.value) {
        router.put('/invoices/' + editingInvoiceId.value, payload, {
            preserveScroll: true,
            onSuccess: () => { showPOS.value = false; editingInvoiceId.value = null; },
            onFinish: () => { submitting.value = false; },
        });
    } else {
        router.post('/invoices', payload, {
            preserveScroll: true,
            onSuccess: () => { showPOS.value = false; },
            onFinish: () => { submitting.value = false; },
        });
    }
};

const viewInv = async (inv) => {
    viewTarget.value = inv;
    try {
        const res = await fetch('/api/invoices/' + inv.id + '/details');
        viewDetails.value = await res.json();
    } catch { viewDetails.value = null; }
};
const approveInv = (inv) => { if(confirm('اعتماد الفاتورة وتحديث الأرصدة؟')) router.post('/invoices/'+inv.id+'/approve',{},{preserveScroll:true}); };
const rejectInv = (inv) => { const r=prompt('سبب الرفض:'); if(r!==null) router.post('/invoices/'+inv.id+'/reject',{reason:r},{preserveScroll:true}); };
const startEditInv = (inv) => { if(confirm('تعديل الفاتورة المعتمدة؟ سيتم عكس الأثر المالي.')) router.post('/invoices/'+inv.id+'/start-edit',{},{preserveScroll:true}); };
const delInv = (inv) => { deleteTarget.value = inv; };
const debounceSearch = () => { clearTimeout(t); t=setTimeout(()=>applyFilter(),400); };
const applyFilter = () => { router.get('/invoices',{search:search.value,status:statusFilter.value},{preserveState:true,replace:true}); };
</script>
