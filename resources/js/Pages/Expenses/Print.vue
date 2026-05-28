<template>
    <PrintLayout>
        <template #title>سند صرف</template>

        <div class="info-grid">
            <div class="info-item"><label>رقم المصروف</label><span style="color:#b8860b;font-weight:bold">{{ expense.expense_number }}</span></div>
            <div class="info-item"><label>التاريخ</label><span class="mono">{{ expense.expense_date?.split('T')[0] }}</span></div>
            <div class="info-item"><label>التصنيف</label><span style="font-weight:bold">{{ expense.category?.name||'—' }}</span></div>
            <div class="info-item"><label>طريقة الدفع</label><span>{{ paymentLabel(expense.payment_method) }}</span></div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px;">
            <div style="background:#fef2f2;padding:12px;border-radius:8px;text-align:center;border:1px solid #fecaca;">
                <p style="font-size:8pt;color:#999;margin:0 0 4px;">المبلغ</p>
                <p class="mono bold red" style="margin:0;font-size:14pt;">{{ fmt(expense.amount) }} {{ expense.currency }}</p>
            </div>
            <div style="background:#f9f9f9;padding:12px;border-radius:8px;text-align:center;border:1px solid #eee;">
                <p style="font-size:8pt;color:#999;margin:0 0 4px;">الحالة</p>
                <p style="margin:0;font-weight:bold;" :style="{color: expense.status==='approved'?'#16a34a':expense.status==='rejected'?'#dc2626':'#ca8a04'}">{{ statusLabel(expense.status) }}</p>
            </div>
        </div>

        <div style="background:#f9f9f9;padding:12px 14px;border-radius:8px;border:1px solid #eee;margin-bottom:16px;">
            <p style="font-size:8pt;color:#999;margin:0 0 4px;">البيان</p>
            <p style="margin:0;font-size:11pt;font-weight:bold;">{{ expense.description }}</p>
        </div>

        <div v-if="expense.notes" style="background:#f9f9f9;padding:10px 14px;border-radius:8px;border:1px solid #eee;margin-bottom:16px;">
            <p style="font-size:8pt;color:#999;margin:0 0 4px;">ملاحظات</p>
            <p style="margin:0;font-size:10pt;">{{ expense.notes }}</p>
        </div>

        <div v-if="expense.reference_number" style="background:#f9f9f9;padding:10px 14px;border-radius:8px;border:1px solid #eee;margin-bottom:16px;">
            <p style="font-size:8pt;color:#999;margin:0 0 4px;">الرقم المرجعي</p>
            <p class="mono" style="margin:0;">{{ expense.reference_number }}</p>
        </div>

        <div style="text-align:center;margin:20px 0 10px;padding:12px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;">
            <p style="font-size:9pt;color:#666;margin:0 0 4px;">المبلغ كتابة</p>
            <p style="margin:0;font-weight:bold;font-size:12pt;">{{ amountInWords(expense.amount) }} {{ expense.currency==='SAR'?'ريال سعودي':'دينار أردني' }} فقط لا غير</p>
        </div>

        <div class="stamp-area">
            <div class="stamp-box"><p>المحاسب</p><div class="line">التوقيع والختم</div></div>
            <div class="stamp-box"><p>المستلم</p><div class="line">التوقيع والختم</div></div>
            <div class="stamp-box"><p>المدير المالي</p><div class="line">التوقيع والختم</div></div>
        </div>
    </PrintLayout>
</template>

<script setup>
import PrintLayout from '@/Components/PrintLayout.vue';
const props = defineProps({ expense: Object });
const decimals = props.expense?.currency === 'SAR' ? 2 : 3;
const fmt = (v) => Number(v||0).toLocaleString('en', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
const paymentLabel = (m) => ({cash:'نقداً',bank:'تحويل بنكي',check:'شيك'}[m]||m);
const statusLabel = (s) => ({pending:'معلق',approved:'معتمد',rejected:'مرفوض'}[s]||s);
const amountInWords = (v) => {
    const n = Math.floor(Number(v||0));
    if (n === 0) return 'صفر';
    const ones = ['','واحد','اثنان','ثلاثة','أربعة','خمسة','ستة','سبعة','ثمانية','تسعة','عشرة','أحد عشر','اثنا عشر','ثلاثة عشر','أربعة عشر','خمسة عشر','ستة عشر','سبعة عشر','ثمانية عشر','تسعة عشر'];
    const tens = ['','','عشرون','ثلاثون','أربعون','خمسون','ستون','سبعون','ثمانون','تسعون'];
    const hundreds = ['','مائة','مئتان','ثلاثمائة','أربعمائة','خمسمائة','ستمائة','سبعمائة','ثمانمائة','تسعمائة'];
    if (n < 20) return ones[n];
    if (n < 100) return (n%10?ones[n%10]+' و':'') + tens[Math.floor(n/10)];
    if (n < 1000) return hundreds[Math.floor(n/100)] + (n%100?' و'+amountInWords(n%100):'');
    if (n < 1000000) return amountInWords(Math.floor(n/1000)) + ' ألف' + (n%1000?' و'+amountInWords(n%1000):'');
    return String(n);
};
</script>
