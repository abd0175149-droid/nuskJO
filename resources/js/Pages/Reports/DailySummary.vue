<template>
    <AppLayout>
        <template #header>الملخص اليومي</template>
        <div class="space-y-6">
            <div class="flex flex-wrap items-end gap-4">
                <div><label class="block text-sm font-medium text-gray-700 mb-1">التاريخ</label><input v-model="selectedDate" type="date" dir="ltr" class="px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none" @change="loadDate"/></div>
                <div class="bg-gray-50 border border-gray-200 rounded-xl px-5 py-3 text-sm">
                    <span class="font-bold">{{ selectedDate }}</span>
                </div>
            </div>

            <!-- Totals -->
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="bg-blue-50 rounded-xl p-4 text-center"><p class="text-xs text-gray-500">سندات قبض</p><p class="font-bold font-mono text-blue-600 mt-1" dir="ltr">{{ fmt(totals.receipts_jod,3) }} JOD</p></div>
                <div class="bg-purple-50 rounded-xl p-4 text-center"><p class="text-xs text-gray-500">فواتير</p><p class="font-bold font-mono text-purple-600 mt-1" dir="ltr">{{ fmt(totals.invoices_jod,3) }} JOD</p></div>
                <div class="bg-orange-50 rounded-xl p-4 text-center"><p class="text-xs text-gray-500">سندات صرف</p><p class="font-bold font-mono text-orange-600 mt-1" dir="ltr">{{ fmt(totals.disbursements_jod,3) }} JOD</p></div>
            </div>

            <!-- Disbursements -->
            <section v-if="disbursements.length" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h4 class="font-bold text-sm text-gray-700 mb-3">📤 سندات الصرف ({{ disbursements.length }})</h4>
                <table class="w-full text-xs"><thead><tr class="bg-gray-50"><th class="px-3 py-2 text-right">الرقم</th><th class="px-3 py-2 text-right">المستفيد</th><th class="px-3 py-2 text-right">المبلغ</th><th class="px-3 py-2 text-right">الحالة</th></tr></thead>
                <tbody><tr v-for="d in disbursements" :key="d.id" class="border-t"><td class="px-3 py-2 font-mono text-right">{{ d.disbursement_number }}</td><td class="px-3 py-2 text-right">{{ d.beneficiary_name }}</td><td class="px-3 py-2 font-mono text-right">{{ Number(d.amount_jod).toFixed(3) }} JOD</td><td class="px-3 py-2 text-right"><span class="px-1.5 py-0.5 rounded text-xs" :class="{'bg-yellow-100 text-yellow-700':d.status==='pending','bg-green-100 text-green-700':d.status==='approved','bg-red-100 text-red-700':d.status==='rejected'}">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة'}[d.status] }}</span></td></tr></tbody></table>
            </section>

            <!-- Receipts -->
            <section v-if="receipts.length" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h4 class="font-bold text-sm text-gray-700 mb-3">📄 سندات القبض ({{ receipts.length }})</h4>
                <table class="w-full text-xs"><thead><tr class="bg-gray-50"><th class="px-3 py-2 text-right">الرقم</th><th class="px-3 py-2 text-right">العميل</th><th class="px-3 py-2 text-right">المبلغ</th><th class="px-3 py-2 text-right">الحالة</th></tr></thead>
                <tbody><tr v-for="r in receipts" :key="r.id" class="border-t"><td class="px-3 py-2 font-mono text-right">{{ r.receipt_number }}</td><td class="px-3 py-2 text-right">{{ r.client?.name }}</td><td class="px-3 py-2 font-mono text-right">{{ Number(r.amount_jod).toFixed(3) }} JOD</td><td class="px-3 py-2 text-right"><span class="px-1.5 py-0.5 rounded text-xs" :class="{'bg-yellow-100 text-yellow-700':r.status==='pending','bg-green-100 text-green-700':r.status==='approved','bg-red-100 text-red-700':r.status==='rejected'}">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة'}[r.status] }}</span></td></tr></tbody></table>
            </section>

            <!-- Invoices -->
            <section v-if="invoices.length" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h4 class="font-bold text-sm text-gray-700 mb-3">🧾 الفواتير ({{ invoices.length }})</h4>
                <table class="w-full text-xs"><thead><tr class="bg-gray-50"><th class="px-3 py-2 text-right">الرقم</th><th class="px-3 py-2 text-right">الوكيل</th><th class="px-3 py-2 text-right">العميل</th><th class="px-3 py-2 text-right">المبلغ JOD</th><th class="px-3 py-2 text-right">الحالة</th></tr></thead>
                <tbody><tr v-for="i in invoices" :key="i.id" class="border-t"><td class="px-3 py-2 font-mono text-right">{{ i.invoice_number }}</td><td class="px-3 py-2 text-right">{{ i.agent?.name }}</td><td class="px-3 py-2 text-right">{{ i.client?.name }}</td><td class="px-3 py-2 font-mono text-right">{{ Number(i.total_jod).toFixed(3) }} JOD</td><td class="px-3 py-2 text-right"><span class="px-1.5 py-0.5 rounded text-xs" :class="{'bg-yellow-100 text-yellow-700':i.status==='pending','bg-green-100 text-green-700':i.status==='approved','bg-red-100 text-red-700':i.status==='rejected'}">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة'}[i.status] }}</span></td></tr></tbody></table>
            </section>

            <p v-if="!disbursements.length && !receipts.length && !invoices.length" class="text-center text-gray-400 py-12">لا يوجد عمليات في هذا اليوم</p>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
const props = defineProps({ date: String, disbursements: Array, receipts: Array, invoices: Array, totals: Object });
const selectedDate = ref(props.date);
const fmt = (v, d) => Number(v||0).toLocaleString('en',{minimumFractionDigits:d,maximumFractionDigits:d});
const loadDate = () => { router.get('/reports/daily-summary',{date:selectedDate.value},{preserveState:true,replace:true}); };
</script>
