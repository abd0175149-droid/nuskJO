<template>
    <div class="print-page" dir="rtl">
        <div class="print-container">
            <div v-if="templateUrl" class="print-bg"><img :src="templateUrl" alt="template" class="w-full"/></div>
            <div class="print-content">
                <h2 class="text-center text-xl font-bold mb-4">سند صرف</h2>
                <table class="w-full text-sm mb-4">
                    <tr><td class="font-bold w-32">رقم السند:</td><td>{{ disbursement.disbursement_number }}</td><td class="font-bold w-32">التاريخ:</td><td>{{ disbursement.disbursement_date }}</td></tr>
                    <tr><td class="font-bold">الحساب:</td><td>{{ disbursement.account?.name }} ({{ disbursement.account?.code }})</td><td class="font-bold">الوكيل:</td><td>{{ disbursement.agent?.name || '—' }}</td></tr>
                    <tr><td class="font-bold">المبلغ:</td><td class="text-lg font-bold">{{ Number(disbursement.amount).toLocaleString() }} {{ disbursement.currency }}</td><td class="font-bold">طريقة الدفع:</td><td>{{ {cash:'نقدي',bank:'بنكي',check:'شيك'}[disbursement.payment_method] }}</td></tr>
                    <tr><td class="font-bold">الوصف:</td><td colspan="3">{{ disbursement.description }}</td></tr>
                    <tr v-if="disbursement.notes"><td class="font-bold">ملاحظات:</td><td colspan="3">{{ disbursement.notes }}</td></tr>
                </table>
                <div class="flex justify-between mt-12 text-sm">
                    <div class="text-center"><div class="border-t border-black w-32 mx-auto mb-1"></div>المحاسب: {{ disbursement.creator?.name }}</div>
                    <div class="text-center"><div class="border-t border-black w-32 mx-auto mb-1"></div>المعتمد: {{ disbursement.approver?.name || '—' }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
<script setup>
import { onMounted } from 'vue';
defineProps({ disbursement: Object, templateUrl: String });
onMounted(() => setTimeout(() => window.print(), 500));
</script>
<style scoped>
.print-page{padding:20px;direction:rtl}
.print-container{max-width:800px;margin:auto;position:relative}
.print-bg{position:absolute;inset:0;z-index:0;opacity:0.1}
.print-content{position:relative;z-index:1}
@media print{body{-webkit-print-color-adjust:exact}}
</style>
