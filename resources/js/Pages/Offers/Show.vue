<template>
    <AppLayout>
        <template #header>{{ isNew ? (form.is_ready ? 'عرض جاهز جديد' : 'عرض جديد') : 'العرض' }}</template>

        <div class="space-y-5">
            <Link :href="from === 'ready' ? '/offers/ready' : '/offers'" class="inline-block text-sm font-bold text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">→ {{ from === 'ready' ? 'العروض الجاهزة' : 'العروض والباقات' }}</Link>

            <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
            <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">❌ {{ $page.props.flash.error }}</div>

            <!-- Header -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-2 min-w-0">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 break-words">{{ form.title || (isNew ? 'عرض جديد' : '—') }}</h2>
                    <div v-if="summary" class="flex flex-wrap items-center gap-1.5">
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ summary.category_label }}</span>
                        <span v-if="summary.is_ready" class="px-2.5 py-1 rounded-full text-xs font-bold bg-gold-100 text-gold-800 dark:bg-gold-900/40 dark:text-gold-200">🎫 جاهز · <span dir="ltr" class="font-mono">{{ summary.trip_date }}</span> · {{ summary.travel_mode_label }}</span>
                        <span v-else class="px-2.5 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">تاريخ مفتوح</span>
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="summary.for_bot ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">🤖 {{ summary.for_bot ? 'يقرؤه البوت' : 'لا يقرؤه البوت' }}</span>
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold" :class="summary.issues_count ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'">{{ summary.issues_count ? 'بيانات البيع ناقصة (' + summary.issues_count + ')' : 'يُباع: بيانات البيع مكتملة' }}</span>
                        <span v-if="summary.is_closed" class="px-2.5 py-1 rounded-full text-xs font-bold bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200">🔒 مُقفل</span>
                        <span v-if="summary.total_seats" class="text-xs text-gray-500">المتاح <b class="font-mono tabular-nums">{{ summary.available }}</b> من <span class="font-mono tabular-nums">{{ summary.total_seats }}</span></span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link v-if="!isNew && can.invoice" :href="'/invoices?offer_new=' + offer.id" class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md" :class="sellBlock ? 'opacity-50 pointer-events-none' : ''" :title="sellBlock || ''">🧾 فاتورة على هذا العرض</Link>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700 overflow-x-auto" role="tablist">
                <button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" @click="setTab(t.key)" :disabled="isNew && t.needsSaved"
                        class="px-4 py-2.5 text-sm font-bold whitespace-nowrap border-b-2 -mb-px disabled:opacity-40 disabled:cursor-not-allowed"
                        :class="tab === t.key ? 'border-gold-500 text-gray-900 dark:text-gray-100' : 'border-transparent text-gray-500 hover:text-gray-800 dark:hover:text-gray-200'">{{ t.label }}</button>
            </div>

            <!-- Save bar (editing tabs) -->
            <div v-if="editable && (isNew || form.isDirty)" class="sticky top-2 z-20 flex flex-wrap items-center justify-between gap-3 p-3 rounded-xl border bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200 text-sm font-bold">
                <span>{{ isNew ? 'عرض جديد لم يُحفظ بعد.' : 'تعديلات غير محفوظة. البوت والبطاقة والفواتير الجديدة ما زالت تقرأ النسخة المحفوظة.' }}</span>
                <div class="flex gap-2">
                    <button type="button" @click="save" :disabled="form.processing" class="px-5 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow disabled:opacity-50">{{ form.processing ? '...جارٍ الحفظ' : (isNew ? '✅ حفظ العرض' : '💾 حفظ التعديلات') }}</button>
                    <button v-if="!isNew" type="button" @click="form.reset()" class="px-4 py-2 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-white/60 dark:hover:bg-gray-800">تجاهل</button>
                </div>
            </div>

            <div v-if="errorList.length" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">
                <b>لم يُحفظ العرض:</b>
                <ul class="list-disc ps-5 mt-1 space-y-0.5"><li v-for="(e, i) in errorList" :key="i">{{ e }}</li></ul>
            </div>

            <div v-if="summary?.is_closed && editable" class="p-3 rounded-xl border text-sm bg-gray-50 border-gray-200 text-gray-600 dark:bg-gray-800/40 dark:border-gray-700 dark:text-gray-300">🔒 العرض مُقفل بعد التسوية، ولا تُحفظ تعديلاته.</div>

            <OfferDataTab v-show="tab === 'data'" :form="form" :meta="meta" :has-sales="hasSales"/>
            <OfferOptionsTab v-show="tab === 'options'" :form="form" :meta="meta" :sold="sold" :has-sales="hasSales" :issues="issues" :is-new="isNew"/>
            <OfferBotTab v-if="!isNew && tab === 'bot'" :offer-id="offer.id" :bot="bot" :can="can" :dirty="form.isDirty"/>
            <OfferSalesTab v-if="!isNew && tab === 'sales'" :sales="sales" :can="can"/>
            <OfferCloseTab v-if="!isNew && tab === 'close'" :offer-id="offer.id" :closing="closing" :agents="agents" :can="can"/>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import OfferDataTab from '@/Components/Offers/OfferDataTab.vue';
import OfferOptionsTab from '@/Components/Offers/OfferOptionsTab.vue';
import OfferBotTab from '@/Components/Offers/OfferBotTab.vue';
import OfferSalesTab from '@/Components/Offers/OfferSalesTab.vue';
import OfferCloseTab from '@/Components/Offers/OfferCloseTab.vue';

const props = defineProps({
    title: String,
    offer: Object,
    tab: String,
    from: String,
    meta: Object,
    agents: Array,
    can: Object,
    summary: Object,
    issues: { type: Array, default: () => [] },
    sold: { type: Object, default: () => ({ stay: [], option: [] }) },
    bot: Object,
    sales: Object,
    closing: Object,
});

const isNew = computed(() => !props.offer?.id);
const hasSales = computed(() => (props.summary?.invoices_count || 0) > 0);
const tabs = computed(() => [
    { key: 'data', label: 'البيانات' },
    { key: 'options', label: props.offer?.category === 'visa' || form.category === 'visa' ? 'السعر' : 'الخيارات والأسعار' },
    { key: 'bot', label: 'ما يراه البوت', needsSaved: true },
    { key: 'sales', label: 'الفواتير والمقاعد', needsSaved: true },
    { key: 'close', label: 'الإقفال', needsSaved: true },
]);
const tab = ref(['data', 'options', 'bot', 'sales', 'close'].includes(props.tab) && !(isNew.value && ['bot', 'sales', 'close'].includes(props.tab)) ? props.tab : 'data');
const editable = computed(() => ['data', 'options'].includes(tab.value) && (isNew.value ? props.can?.create : props.can?.update) && !props.summary?.is_closed);
const setTab = (k) => {
    tab.value = k;
    const url = new URL(window.location.href);
    url.searchParams.set('tab', k);
    window.history.replaceState(window.history.state, '', url);
};

// نسخة عميقة لكل عنصر مع مفتاح محلي ثابت للقوائم
let uid = 0;
const withKeys = (o) => {
    const c = JSON.parse(JSON.stringify(o || {}));
    (c.groups || []).forEach((g) => {
        g._k = ++uid;
        (g.options || []).forEach((opt) => {
            opt._k = ++uid;
            (opt.stays || []).forEach((s) => { s._k = ++uid; });
        });
    });
    return c;
};
const form = useForm(withKeys(props.offer));

const errorList = computed(() => Object.values(form.errors).flat().filter(Boolean));

const sellBlock = computed(() => {
    const s = props.summary;
    if (!s) return 'احفظ العرض أولاً';
    if (s.is_closed) return 'العرض مُقفل';
    if (!s.is_active) return 'العرض غير فعّال';
    if (s.issues_count) return 'بيانات البيع ناقصة';
    if (s.is_ready && s.available !== null && s.available <= 0) return 'مكتمل';
    if (s.is_ready && s.state?.label && ['في الرحلة', 'عادت — بانتظار الإقفال'].includes(s.state.label)) return 'أقلعت الرحلة';
    return '';
});

const txt = (v) => { const s = String(v ?? '').trim(); return s === '' ? null : s; };
const num = (v) => (v === null || v === undefined || String(v).trim() === '' ? null : Number(v));
const strip = (o) => { const c = { ...o }; delete c._k; return c; };

const save = () => {
    form.transform((d) => ({
        ...d,
        title: String(d.title || '').trim(),
        description_client: txt(d.description_client),
        notes_public: txt(d.notes_public),
        valid_from: txt(d.valid_from),
        valid_to: txt(d.valid_to),
        trip_date: d.is_ready ? txt(d.trip_date) : null,
        return_date: d.is_ready ? txt(d.return_date) : null,
        total_seats: num(d.total_seats),
        nights: num(d.nights),
        airline: txt(d.airline),
        base_sell_pp: num(d.base_sell_pp) ?? 0,
        price_per_person: num(d.price_per_person),
        includes: (d.includes || []).map((x) => String(x || '').trim()).filter(Boolean),
        excludes: (d.excludes || []).map((x) => String(x || '').trim()).filter(Boolean),
        groups: d.category === 'visa' ? [] : (d.groups || []).map((g) => ({
            ...strip(g),
            nights: num(g.nights),
            options: (g.options || []).map((o) => ({
                ...strip(o),
                sell_flat: num(o.sell_flat) ?? 0,
                capacity: num(o.capacity),
                stays: (o.stays || []).map((s) => ({ ...strip(s), rating: num(s.rating), nights: num(s.nights) })),
            })),
        })),
    }));
    const opts = { preserveScroll: true, preserveState: false };
    isNew.value ? form.post('/offers?tab=' + tab.value, opts) : form.put('/offers/' + props.offer.id, opts);
};
</script>
