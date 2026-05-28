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
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="bg-green-50 rounded-xl p-4 text-center"><p class="text-xs text-gray-500">حوالات</p><p class="font-bold font-mono text-green-600 mt-1" dir="ltr">{{ fmt(totals.transfers_sar,2) }} SAR</p></div>
                <div class="bg-blue-50 rounded-xl p-4 text-center"><p class="text-xs text-gray-500">سندات قبض</p><p class="font-bold font-mono text-blue-600 mt-1" dir="ltr">{{ fmt(totals.receipts_jod,3) }} JOD</p></div>
                <div class="bg-purple-50 rounded-xl p-4 text-center"><p class="text-xs text-gray-500">فواتير</p><p class="font-bold font-mono text-purple-600 mt-1" dir="ltr">{{ fmt(totals.invoices_jod,3) }} JOD</p></div>
                <div class="bg-red-50 rounded-xl p-4 text-center"><p class="text-xs text-gray-500">مخالفات</p><p class="font-bold font-mono text-red-600 mt-1" dir="ltr">{{ fmt(totals.violations_sar,2) }} SAR</p></div>
                <div class="bg-orange-50 rounded-xl p-4 text-center"><p class="text-xs text-gray-500">مصاريف</p><p class="font-bold font-mono text-orange-600 mt-1" dir="ltr">{{ fmt(totals.expenses,2) }}</p></div>
            </div>

            <!-- Transfers -->
            <section v-if="transfers.length" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h4 class="font-bold text-sm text-gray-700 mb-3">💱 الحوالات ({{ transfers.length }})</h4>
                <table class="w-full text-xs"><thead><tr class="bg-gray-50"><th class="px-3 py-2 text-right">الرقم</th><th class="px-3 py-2 text-right">الوكيل</th><th class="px-3 py-2 text-right">المبلغ</th><th class="px-3 py-2 text-right">الحالة</th></tr></thead>
                <tbody><tr v-for="t in transfers" :key="t.id" class="border-t"><td class="px-3 py-2 font-mono text-right">{{ t.transfer_number }}</td><td class="px-3 py-2 text-right">{{ t.agent?.name }}</td><td class="px-3 py-2 font-mono text-right">{{ Number(t.amount_sar).toFixed(2) }} SAR</td><td class="px-3 py-2 text-right"><span class="px-1.5 py-0.5 rounded text-xs" :class="{'bg-yellow-100 text-yellow-700':t.status==='pending','bg-green-100 text-green-700':t.status==='approved','bg-red-100 text-red-700':t.status==='rejected'}">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة'}[t.status] }}</span></td></tr></tbody></table>
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

            <!-- Violations -->
            <section v-if="violations.length" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h4 class="font-bold text-sm text-gray-700 mb-3">⚠️ المخالفات ({{ violations.length }})</h4>
                <table class="w-full text-xs"><thead><tr class="bg-gray-50"><th class="px-3 py-2 text-right">الرقم</th><th class="px-3 py-2 text-right">الوكيل</th><th class="px-3 py-2 text-right">النوع</th><th class="px-3 py-2 text-right">التكلفة</th><th class="px-3 py-2 text-right">الحالة</th></tr></thead>
                <tbody><tr v-for="v in violations" :key="v.id" class="border-t"><td class="px-3 py-2 font-mono text-right">{{ v.violation_number }}</td><td class="px-3 py-2 text-right">{{ v.agent?.name }}</td><td class="px-3 py-2 text-right">{{ v.violation_type?.name }}</td><td class="px-3 py-2 font-mono text-right">{{ Number(v.cost_sar).toFixed(2) }} SAR</td><td class="px-3 py-2 text-right"><span class="px-1.5 py-0.5 rounded text-xs" :class="{'bg-yellow-100 text-yellow-700':v.status==='pending','bg-green-100 text-green-700':v.status==='approved','bg-red-100 text-red-700':v.status==='rejected'}">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة'}[v.status] }}</span></td></tr></tbody></table>
            </section>

            <!-- Expenses -->
            <section v-if="expenses.length" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h4 class="font-bold text-sm text-gray-700 mb-3">💰 المصاريف ({{ expenses.length }})</h4>
                <table class="w-full text-xs"><thead><tr class="bg-gray-50"><th class="px-3 py-2 text-right">الرقم</th><th class="px-3 py-2 text-right">التصنيف</th><th class="px-3 py-2 text-right">الوصف</th><th class="px-3 py-2 text-right">المبلغ</th><th class="px-3 py-2 text-right">الحالة</th></tr></thead>
                <tbody><tr v-for="e in expenses" :key="e.id" class="border-t"><td class="px-3 py-2 font-mono text-right">{{ e.expense_number }}</td><td class="px-3 py-2 text-right">{{ e.category?.name }}</td><td class="px-3 py-2 text-right">{{ e.description }}</td><td class="px-3 py-2 font-mono text-right">{{ Number(e.amount).toFixed(2) }} {{ e.currency }}</td><td class="px-3 py-2 text-right"><span class="px-1.5 py-0.5 rounded text-xs" :class="{'bg-yellow-100 text-yellow-700':e.status==='pending','bg-green-100 text-green-700':e.status==='approved','bg-red-100 text-red-700':e.status==='rejected'}">{{ {pending:'معلقة',approved:'معتمدة',rejected:'مرفوضة'}[e.status] }}</span></td></tr></tbody></table>
            </section>

            <p v-if="!transfers.length && !receipts.length && !invoices.length && !violations.length && !expenses.length" class="text-center text-gray-400 py-12">لا يوجد عمليات في هذا اليوم</p>
        </div>
    </AppLayout>
</template>
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
const props = defineProps({ date: String, transfers: Array, receipts: Array, invoices: Array, violations: Array, expenses: Array, totals: Object });
const selectedDate = ref(props.date);
const fmt = (v, d) => Number(v||0).toLocaleString('en',{minimumFractionDigits:d,maximumFractionDigits:d});
const loadDate = () => { router.get('/reports/daily-summary',{date:selectedDate.value},{preserveState:true,replace:true}); };
</script>
