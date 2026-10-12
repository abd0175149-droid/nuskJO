<template>
    <div class="space-y-5">
        <!-- العميل والبائع -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="oi-name" :class="lbl">اسم العميل *</label>
                <input id="oi-name" v-model="st.client_name" list="oi-clients" autocomplete="off" :class="inp" @input="onName"/>
                <datalist id="oi-clients"><option v-for="c in clients" :key="c.id" :value="c.name"></option></datalist>
            </div>
            <div>
                <label for="oi-phone" :class="lbl">هاتف العميل *</label>
                <input id="oi-phone" v-model="st.client_phone" inputmode="tel" dir="ltr" placeholder="07XXXXXXXX" :class="inp + ' font-mono'"/>
            </div>
            <div>
                <label :class="lbl">البائع (تُنسب إليه الأرباح)</label>
                <SearchableSelect v-model="st.sold_by" :options="employeeOptions" placeholder="اختر الموظف البائع" search-placeholder="ابحث عن موظف..."/>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs min-h-[22px]">
            <template v-if="phoneDigits.length >= 9">
                <span v-if="matched" class="px-2 py-0.5 rounded-full font-bold bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">✓ عميل مسجّل</span>
                <span v-else class="px-2 py-0.5 rounded-full font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">عميل جديد</span>
                <span class="text-gray-500">{{ matched ? matched.name + ' · ' + matched.code + (st.client_name.trim() && matched.name !== st.client_name.trim() ? ' · الاسم في السجل يختلف' : '') : 'يُنشأ عند الحفظ بهذا الاسم والرقم.' }}</span>
            </template>
            <span v-else class="text-gray-400">أدخل الهاتف ليُبحث عن العميل.</span>
        </div>
        <div v-if="note" class="p-3 rounded-xl border text-sm bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-200">{{ note }}</div>

        <!-- تاريخ الرحلة -->
        <div class="space-y-2">
            <h4 :class="h4">تاريخ الرحلة <span class="font-normal text-xs text-gray-400">التواريخ التي فيها عروض جاهزة، أو أي تاريخ آخر</span></h4>
            <div v-if="locked" class="flex items-center gap-2"><span class="px-3 py-1 rounded-full text-sm font-bold bg-gold-100 text-gold-800 font-mono" dir="ltr">{{ st.trip_date }}</span><span class="text-xs text-gray-500">لا يتغير العرض في التعديل.</span></div>
            <div v-else class="flex flex-wrap gap-2">
                <button v-for="d in dates" :key="d.date" type="button" @click="pickDate(d.date)" :aria-pressed="st.trip_date === d.date"
                        class="px-3 py-1.5 rounded-xl border text-start" :class="st.trip_date === d.date ? 'border-gold-500 bg-gold-50 dark:bg-gold-900/30' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-gold-400'">
                    <b class="block text-sm font-mono" dir="ltr">{{ d.date }}</b><span class="text-[11px] text-gray-500">{{ d.count }} {{ d.count > 2 && d.count < 11 ? 'عروض' : 'عرض' }}</span>
                </button>
                <label for="oi-date" class="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs text-gray-500">تاريخ آخر
                    <input id="oi-date" v-model="st.trip_date" type="date" dir="ltr" class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm" @change="onDate"/>
                </label>
            </div>
        </div>

        <!-- العروض في التاريخ -->
        <div class="space-y-2">
            <h4 :class="h4">العروض في هذا التاريخ <span class="font-normal text-xs text-gray-400">{{ st.trip_date ? onDateList.length + ' · الجاهزة بتاريخها، والمفتوحة إن كانت صالحة فيه' : 'اختر التاريخ أولاً' }}</span></h4>
            <div v-if="onDateList.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <button v-for="o in onDateList" :key="o.id" type="button" @click="selectOffer(o)" :disabled="(!!blockFor(o) && st.offer_id !== o.id) || locked" :aria-pressed="st.offer_id === o.id"
                        class="p-3 rounded-xl border text-start space-y-1 disabled:cursor-not-allowed"
                        :class="st.offer_id === o.id ? 'border-gold-500 bg-gold-50 dark:bg-gold-900/30 ring-1 ring-gold-500' : (blockFor(o) ? 'border-gray-200 dark:border-gray-700 opacity-60' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-gold-400')">
                    <b class="block text-sm text-gray-800 dark:text-gray-100">{{ o.title }}</b>
                    <span class="flex flex-wrap gap-1 text-[11px]">
                        <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ o.category_label }}</span>
                        <span v-if="o.is_ready" class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ o.travel_mode === 'air' ? '✈️' : '🚌' }} {{ o.travel_mode_label }}</span>
                        <span v-else class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">تاريخ مفتوح</span>
                    </span>
                    <span class="block text-xs text-gray-500">{{ o.is_ready ? '📍 ' + (o.destination || '') + ' · ' : '' }}{{ o.total_seats ? 'المتاح ' + o.available + ' من ' + o.total_seats : 'بلا حد مقاعد' }}{{ o.price_from ? ' · يبدأ من ' + money(o.price_from) : '' }}</span>
                    <span v-if="blockFor(o)" class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ blockFor(o) }}</span>
                </button>
            </div>
            <p v-else-if="st.trip_date" class="p-3 rounded-xl text-sm text-gray-500 bg-gray-50 dark:bg-gray-800/40">لا عروض في هذا التاريخ.</p>
        </div>

        <!-- سطور الحجز -->
        <div v-if="offer" class="space-y-3">
            <h4 :class="h4">سطور الحجز <span class="font-normal text-xs text-gray-400">قائمة منسدلة لكل ما فيه خيار{{ offer.has_stay ? '، وسعة الغرفة' : '' }}، والمقاعد لكل سطر</span></h4>
            <div v-for="(l, i) in st.lines" :key="i" class="rounded-xl border p-3 space-y-2 border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/40">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2"><b class="text-sm">السطر {{ i + 1 }}</b><span v-if="preview?.lines?.[i]?.kept" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-700">تكاليف محفوظة كما بيعت</span></div>
                    <button type="button" @click="st.lines.splice(i, 1)" :disabled="st.lines.length < 2" class="px-2 text-lg text-gray-400 hover:text-red-500 disabled:opacity-30" aria-label="حذف السطر">&times;</button>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
                    <div v-for="g in offer.groups" :key="g.id">
                        <label :for="'oi-l' + i + '-g' + g.id" :class="mini">{{ g.label }}</label>
                        <select :id="'oi-l' + i + '-g' + g.id" v-model.number="l.sel[g.id]" :class="inpSm" @change="fixRoom(l)">
                            <option v-for="opt in g.options.filter((x) => x.is_active || x.id === l.sel[g.id])" :key="opt.id" :value="opt.id">{{ optText(g, opt, l) }}</option>
                        </select>
                    </div>
                    <div v-if="offer.has_stay">
                        <label :for="'oi-l' + i + '-room'" :class="mini">سعة الغرفة</label>
                        <select :id="'oi-l' + i + '-room'" v-model="l.room" :class="inpSm">
                            <option v-for="(rl, rk) in roomTypes" :key="rk" :value="rk" :disabled="!roomsFor(l.sel).includes(rk)">{{ rl }}{{ roomsFor(l.sel).includes(rk) ? '' : ' — غير متاحة' }}</option>
                        </select>
                    </div>
                    <div>
                        <label :for="'oi-l' + i + '-pax'" :class="mini">المسافرون (مقاعد)</label>
                        <input :id="'oi-l' + i + '-pax'" v-model.number="l.pax" type="number" min="1" dir="ltr" :class="inpSm + ' font-mono'"/>
                    </div>
                </div>
                <p v-if="preview?.lines?.[i]" class="text-xs text-gray-500">
                    سعر الفرد <b class="font-mono" dir="ltr">{{ money(preview.lines[i].sell_pp) }}</b> · تكلفة الفرد <b class="font-mono" dir="ltr">{{ money(preview.lines[i].cost_pp) }}</b>
                    <span v-if="preview.lines[i].kept && preview.lines[i].cost_now !== null && Math.abs(preview.lines[i].cost_now - preview.lines[i].cost_pp) > 0.0005" class="text-amber-600"> · تكلفة العرض الآن {{ money(preview.lines[i].cost_now) }} (فعّل «إعادة الحساب» لاعتمادها)</span>
                </p>
            </div>
            <button v-if="offer.groups.length" type="button" @click="st.lines.push(defaultLine(offer))" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200">+ سطر حجز آخر (سعة أو فنادق مختلفة)</button>

            <!-- بنود إضافية -->
            <div class="space-y-2">
                <h4 :class="h4">بنود إضافية خارج العرض <span class="font-normal text-xs text-gray-400">اختيارية: ليلة إضافية، تذكرة… بوكيلها وتكلفتها وسعرها</span></h4>
                <div v-for="(e, i) in st.extras" :key="'x' + i" class="grid grid-cols-2 md:grid-cols-6 gap-2 items-end p-2 rounded-lg bg-gray-50 dark:bg-gray-800/40">
                    <div class="col-span-2">
                        <label :class="mini">الوكيل *</label>
                        <SearchableSelect v-model="e.agent_id" :options="agentOptions" placeholder="الوكيل" search-placeholder="ابحث..."/>
                    </div>
                    <div class="col-span-2"><label :for="'oi-x' + i + '-d'" :class="mini">البيان *</label><input :id="'oi-x' + i + '-d'" v-model="e.description" :class="inpSm" placeholder="ليلة إضافية في مكة"/></div>
                    <div><label :for="'oi-x' + i + '-q'" :class="mini">العدد</label><input :id="'oi-x' + i + '-q'" v-model.number="e.qty" type="number" min="1" dir="ltr" :class="inpSm + ' font-mono'"/></div>
                    <div class="flex gap-1 items-end">
                        <div class="flex-1"><label :for="'oi-x' + i + '-c'" :class="mini">تكلفة الوحدة</label><input :id="'oi-x' + i + '-c'" v-model.number="e.cost" type="number" step="0.001" min="0" dir="ltr" :class="inpSm + ' font-mono'"/></div>
                        <div class="flex-1"><label :for="'oi-x' + i + '-s'" :class="mini">بيع الوحدة</label><input :id="'oi-x' + i + '-s'" v-model.number="e.sell" type="number" step="0.001" min="0" dir="ltr" :class="inpSm + ' font-mono'"/></div>
                        <button type="button" @click="st.extras.splice(i, 1)" class="px-2 pb-1 text-lg text-gray-400 hover:text-red-500" aria-label="حذف البند">&times;</button>
                    </div>
                </div>
                <button type="button" @click="st.extras.push({ agent_id: null, description: '', qty: 1, cost: 0, sell: 0 })" class="px-3 py-1.5 rounded-lg text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100">+ بند إضافي</button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="oi-disc" :class="lbl">خصم على الفاتورة (د.أ)</label>
                    <input id="oi-disc" v-model.number="st.discount" type="number" step="0.001" min="0" dir="ltr" placeholder="0.000" :class="inp + ' font-mono'"/>
                </div>
                <div v-if="editing" class="flex items-end">
                    <label for="oi-refresh" class="flex items-center gap-2 text-sm cursor-pointer pb-2"><input id="oi-refresh" v-model="st.refresh" type="checkbox" class="w-4 h-4 rounded text-gold-500"/> إعادة حساب التكاليف بأسعار العرض الحالية</label>
                </div>
            </div>

            <!-- الملخص من الخادم -->
            <div v-if="preview" class="space-y-3">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="rounded-xl border p-3 border-gray-200 dark:border-gray-700"><span class="block text-xs text-gray-500">البيع على العميل</span><b class="font-mono text-lg" dir="ltr">{{ money(preview.sell) }}</b> <small class="text-gray-400">{{ preview.discount ? 'بعد خصم ' + money(preview.discount) : 'د.أ' }}</small></div>
                    <div class="rounded-xl border p-3 border-gray-200 dark:border-gray-700"><span class="block text-xs text-gray-500">التكلفة على الوكلاء</span><b class="font-mono text-lg" dir="ltr">{{ money(preview.cost) }}</b> <small class="text-gray-400">{{ preview.agents.length }} وكلاء</small></div>
                    <div class="rounded-xl border p-3 border-gray-200 dark:border-gray-700"><span class="block text-xs text-gray-500">الربح</span><b class="font-mono text-lg" dir="ltr" :class="preview.profit >= 0 ? 'text-green-600' : 'text-red-600'">{{ money(preview.profit) }}</b> <small class="text-gray-400">{{ preview.sell ? ((preview.profit / preview.sell) * 100).toFixed(1) + '%' : '' }}</small></div>
                    <div class="rounded-xl border p-3 border-gray-200 dark:border-gray-700"><span class="block text-xs text-gray-500">المقاعد</span><b class="font-mono text-lg" :class="preview.available !== null && preview.pax > preview.available ? 'text-red-600' : ''">{{ preview.pax }}</b> <small class="text-gray-400">{{ preview.available !== null ? 'المتاح ' + preview.available + ' → يبقى ' + (preview.available - preview.pax) : 'بلا حد' }}</small></div>
                </div>
                <details class="rounded-xl border p-3 border-gray-200 dark:border-gray-700" open>
                    <summary class="cursor-pointer text-sm font-bold">ما سيُقيَّد على كل وكيل عند الاعتماد</summary>
                    <table class="w-full text-xs mt-2">
                        <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-gray-500"><th class="px-2 py-1.5 text-right">الوكيل</th><th class="px-2 py-1.5 text-right">البنود</th><th class="px-2 py-1.5 text-right">الإجمالي</th></tr></thead>
                        <tbody>
                            <tr v-for="(a, k) in preview.agents" :key="k" class="border-t border-gray-100 dark:border-gray-700/50 align-top">
                                <td class="px-2 py-1.5 font-bold">{{ a.agent }}</td>
                                <td class="px-2 py-1.5"><div v-for="(it, j) in a.items" :key="j" class="text-gray-600 dark:text-gray-300">{{ it.description }} · <span class="font-mono" dir="ltr">{{ it.qty }} × {{ money(it.unit) }}</span><span v-if="it.currency === 'SAR'" class="text-gray-400 font-mono"> ({{ money(it.original) }} ريال)</span></div></td>
                                <td class="px-2 py-1.5 font-mono" dir="ltr">{{ money(a.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </details>
            </div>
        </div>

        <div>
            <label for="oi-notes" :class="lbl">ملاحظات</label>
            <textarea id="oi-notes" v-model="st.notes" rows="2" :class="inp + ' resize-none'"></textarea>
        </div>

        <ul v-if="allErrors.length" class="p-3 ps-7 rounded-xl text-sm list-disc bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300 space-y-0.5">
            <li v-for="(e, i) in allErrors" :key="i">{{ e }}</li>
        </ul>

        <div class="flex gap-3">
            <button type="button" @click="submit" :disabled="busy || !st.offer_id" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">{{ editing ? '📤 حفظ وإرسال للاعتماد' : '🧾 إرسال للاعتماد' }}</button>
            <button type="button" @click="$emit('close')" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import SearchableSelect from '@/Components/SearchableSelect.vue';

const props = defineProps({
    catalog: Array, roomTypes: Object, today: String, clients: Array, agents: Array, employees: Array,
    currentEmployeeId: [Number, String], editing: Object, prefill: Object, presetOfferId: Number,
});
const emit = defineEmits(['close']);

const lbl = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
const mini = 'block text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-0.5';
const h4 = 'text-sm font-bold text-gray-800 dark:text-gray-100';
const inp = 'w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none';
const inpSm = 'w-full px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none';
const money = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const employeeOptions = computed(() => props.employees.map((e) => ({ value: e.id, label: e.name })));
const agentOptions = computed(() => props.agents.map((a) => ({ value: a.id, label: `${a.name} (${a.code})` })));

// العروض المتاحة: الكتالوج، ومعه عرض الفاتورة المعدّلة أو عرض طلب التسعير إن لم يكن فيه
const offers = computed(() => {
    const list = [...(props.catalog || [])];
    for (const extra of [props.editing?.offer_form, props.prefill?.offer]) {
        if (extra && !list.some((o) => o.id === extra.id)) list.push(extra);
    }
    return list;
});
const locked = computed(() => !!props.editing);

const st = reactive({
    client_name: '', client_phone: '', sold_by: props.currentEmployeeId || '', trip_date: '', offer_id: null,
    lines: [], extras: [], discount: 0, notes: '', refresh: false, quote_request_id: null,
});
const note = ref('');
const preview = ref(null);
const serverErrors = ref([]);
const busy = ref(false);

const offer = computed(() => offers.value.find((o) => o.id === st.offer_id) || null);

/* ===== العميل ===== */
const normPhone = (p) => {
    let d = String(p || '').replace(/\D+/g, '');
    if (d.startsWith('00')) d = d.slice(2);
    if (d.startsWith('962')) d = '0' + d.slice(3);
    if (d.length === 9 && d[0] === '7') d = '0' + d;
    return d;
};
const phoneDigits = computed(() => normPhone(st.client_phone));
const matched = computed(() => (phoneDigits.value.length >= 9 ? props.clients.find((c) => normPhone(c.phone) === phoneDigits.value) || null : null));
const onName = () => {
    if (st.client_phone) return;
    const c = props.clients.find((x) => x.name === st.client_name.trim());
    if (c?.phone) st.client_phone = c.phone;
};

/* ===== التاريخ والعروض ===== */
const dates = computed(() => {
    const m = {};
    offers.value.filter((o) => o.is_ready && o.trip_date > props.today).forEach((o) => { m[o.trip_date] = (m[o.trip_date] || 0) + 1; });
    return Object.entries(m).sort((a, b) => a[0].localeCompare(b[0])).map(([date, count]) => ({ date, count }));
});
const onDateList = computed(() => {
    const d = st.trip_date;
    if (!d) return [];
    const ready = offers.value.filter((o) => o.is_ready && o.trip_date === d);
    const open = offers.value.filter((o) => !o.is_ready && (!o.valid_from || o.valid_from <= d) && (!o.valid_to || o.valid_to >= d));
    return [...ready, ...open];
});
const blockFor = (o) => {
    if (o.block && !(locked.value && o.id === st.offer_id)) return o.block;
    if (!o.is_ready && st.trip_date && ((o.valid_from && st.trip_date < o.valid_from) || (o.valid_to && st.trip_date > o.valid_to))) return 'خارج مدة العرض';
    return '';
};
const pickDate = (d) => { st.trip_date = d; onDate(); };
const onDate = () => {
    if (!onDateList.value.some((o) => o.id === st.offer_id)) { st.offer_id = null; st.lines = []; preview.value = null; }
    const sellable = onDateList.value.filter((o) => o.is_ready && !blockFor(o));
    if (!st.offer_id && sellable.length === 1) selectOffer(sellable[0]);
};

/* ===== السطور ===== */
const roomsFor = (sel) => {
    const o = offer.value;
    if (!o || !o.has_stay) return [];
    let rooms = Object.keys(props.roomTypes);
    for (const g of o.groups.filter((x) => x.kind === 'stay')) {
        const opt = g.options.find((x) => x.id === sel[g.id]);
        rooms = rooms.filter((r) => opt && (opt.rooms || []).includes(r));
    }
    return rooms;
};
const defaultLine = (o) => {
    const sel = {};
    o.groups.forEach((g) => { const a = g.options.find((x) => x.is_active); if (a) sel[g.id] = a.id; });
    const l = { sel, room: null, pax: 1 };
    fixRoom(l, o);
    return l;
};
const fixRoom = (l, o = offer.value) => {
    if (!o?.has_stay) { l.room = null; return; }
    const rooms = roomsFor(l.sel);
    if (!rooms.includes(l.room)) l.room = ['triple', 'double', 'quad', 'single'].find((r) => rooms.includes(r)) || null;
};
const selectOffer = (o) => {
    if (st.offer_id === o.id) return;
    st.offer_id = o.id;
    st.lines = [defaultLine(o)];
    st.extras = [];
    serverErrors.value = [];
};
const optText = (g, opt, l) => {
    if (g.kind === 'stay') {
        const p = opt.prices?.[l.room];
        return opt.label + (p ? ` (${p})` : ' (—)');
    }
    const full = opt.capacity && opt.used >= opt.capacity ? ' — ممتلئ' : '';
    return opt.label + (Number(opt.sell_flat) ? ` (+${Number(opt.sell_flat)})` : '') + full;
};

/* ===== المعاينة من الخادم ===== */
let timer = null;
const payloadLines = () => st.lines.map((l) => ({ sel: { ...l.sel }, room: offer.value?.has_stay ? l.room : null, pax: l.pax }));
const payloadExtras = () => st.extras.map((e) => ({ agent_id: e.agent_id, description: e.description, qty: e.qty, cost: e.cost, sell: e.sell }));
const runPreview = async () => {
    if (!st.offer_id || !st.lines.length) { preview.value = null; return; }
    try {
        const { data } = await axios.post('/invoices/offer-preview', {
            offer_id: st.offer_id, invoice_id: props.editing?.id || null, lines: payloadLines(),
            extras: payloadExtras(), discount: st.discount || 0, refresh: st.refresh,
        });
        preview.value = data;
    } catch (e) {
        preview.value = null;
    }
};
watch(() => JSON.stringify([st.offer_id, st.lines, st.extras, st.discount, st.refresh]), () => {
    clearTimeout(timer);
    timer = setTimeout(runPreview, 250);
});

const allErrors = computed(() => [...serverErrors.value, ...(preview.value?.errors || [])].filter((v, i, a) => v && a.indexOf(v) === i));

/* ===== الحفظ ===== */
const submit = () => {
    busy.value = true;
    serverErrors.value = [];
    const payload = {
        mode: 'offer',
        client_name: st.client_name, client_phone: st.client_phone, sold_by: st.sold_by || null,
        trip_date: st.trip_date, offer_id: st.offer_id, lines: payloadLines(), extras: payloadExtras(),
        discount: st.discount || 0, notes: st.notes || null, quote_request_id: st.quote_request_id, refresh_costs: st.refresh,
    };
    const opts = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (errs) => { serverErrors.value = Object.values(errs).flat(); },
        onFinish: () => { busy.value = false; },
    };
    props.editing ? router.put('/invoices/' + props.editing.id, payload, opts) : router.post('/invoices', payload, opts);
};

/* ===== التهيئة: تعديل، أو طلب تسعير، أو عرض محدّد ===== */
if (props.editing) {
    const e = props.editing;
    const snap = e.offer_lines || {};
    st.client_name = e.client?.name || '';
    st.client_phone = e.client_phone || e.client?.phone || '';
    st.sold_by = e.sold_by || '';
    st.trip_date = String(e.trip_date || '').slice(0, 10);
    st.offer_id = e.offer_id;
    st.lines = (snap.lines || []).map((l) => ({ sel: Object.fromEntries(Object.entries(l.sel || {}).map(([k, v]) => [k, Number(v)])), room: l.room, pax: l.pax }));
    st.extras = (snap.extras || []).map((x) => ({ agent_id: x.agent_id, description: x.description, qty: x.qty, cost: x.cost, sell: x.sell }));
    st.discount = snap.discount || 0;
    st.notes = e.notes || '';
} else if (props.prefill) {
    const p = props.prefill;
    st.client_name = p.client_name || '';
    st.client_phone = p.client_phone || '';
    st.trip_date = p.trip_date || '';
    st.quote_request_id = p.quote_id;
    note.value = (p.number ? `من طلب ${p.number}. ` : '') + (p.note || '');
    const o = offers.value.find((x) => x.id === p.offer_id);
    if (o) {
        st.offer_id = o.id;
        st.lines = (p.lines || []).map((l) => {
            const base = defaultLine(o);
            if (l.sel) { base.sel = { ...base.sel, ...Object.fromEntries(Object.entries(l.sel).map(([k, v]) => [k, Number(v)])) }; base.room = l.room; fixRoom(base, o); }
            base.pax = l.pax || 1;
            return base;
        });
    }
} else if (props.presetOfferId) {
    const o = offers.value.find((x) => x.id === props.presetOfferId);
    if (o) {
        st.trip_date = o.is_ready ? o.trip_date : (o.valid_from && o.valid_from > props.today ? o.valid_from : props.today);
        if (!blockFor(o)) selectOffer(o);
    }
}
runPreview();
</script>
