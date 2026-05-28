<template>
    <div class="print-page" dir="rtl">
        <div class="print-header">
            <div class="company-info">
                <h1 class="company-name">{{ companyName }}</h1>
                <p class="company-sub">{{ companySubtitle }}</p>
            </div>
            <div class="doc-info">
                <h2 class="doc-title"><slot name="title"/></h2>
                <p class="doc-date">تاريخ الطباعة: {{ printDate }}</p>
            </div>
        </div>
        <hr class="divider"/>
        <slot/>
        <div class="print-footer">
            <p>{{ companyName }} — نظام إدارة مالية</p>
        </div>
    </div>
</template>

<script setup>
import { onMounted } from 'vue';
const companyName = 'شركة صهيب الشاقدلي';
const companySubtitle = 'للسياحة والسفر والحج والعمرة';
const printDate = new Date().toLocaleDateString('ar-SA', { year:'numeric', month:'long', day:'numeric' });
onMounted(() => { setTimeout(() => window.print(), 500); });
</script>

<style>
@media print {
    body { margin: 0; padding: 0; background: white; }
    .print-page { padding: 20mm; font-family: 'Segoe UI', Tahoma, sans-serif; color: #111; font-size: 11pt; }
}
@media screen {
    body { background: #e5e7eb; margin: 0; }
    .print-page { max-width: 210mm; margin: 20px auto; padding: 20mm; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-radius: 8px; font-family: 'Segoe UI', Tahoma, sans-serif; color: #111; font-size: 11pt; }
}
.print-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
.company-name { font-size: 22pt; font-weight: 900; color: #b8860b; margin: 0; }
.company-sub { font-size: 10pt; color: #666; margin: 2px 0 0; }
.doc-title { font-size: 14pt; font-weight: 700; margin: 0; color: #333; }
.doc-date { font-size: 9pt; color: #999; margin: 4px 0 0; }
.divider { border: none; border-top: 2px solid #b8860b; margin: 12px 0 16px; }
.print-footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #ddd; text-align: center; font-size: 9pt; color: #999; }
.info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px; }
.info-item label { display: block; font-size: 8pt; color: #999; margin-bottom: 2px; }
.info-item span { font-size: 10pt; font-weight: 600; }
.print-table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 10pt; }
.print-table th { background: #f5f5f5; padding: 8px 10px; text-align: right; font-weight: 700; border-bottom: 2px solid #ddd; }
.print-table td { padding: 6px 10px; border-bottom: 1px solid #eee; }
.print-table tr:last-child td { border-bottom: none; }
.print-table .mono { font-family: monospace; direction: ltr; text-align: left; }
.print-table .red { color: #dc2626; }
.print-table .green { color: #16a34a; }
.print-table .bold { font-weight: 700; }
.summary-box { background: #f9f9f9; border: 1px solid #ddd; border-radius: 8px; padding: 12px 16px; margin-top: 16px; }
.summary-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 10pt; }
.summary-row.total { font-weight: 900; font-size: 12pt; border-top: 2px solid #b8860b; margin-top: 6px; padding-top: 8px; }
.stamp-area { display: flex; justify-content: space-between; margin-top: 40px; }
.stamp-box { text-align: center; width: 30%; }
.stamp-box p { font-size: 9pt; color: #666; margin-bottom: 40px; }
.stamp-box .line { border-top: 1px solid #333; padding-top: 4px; font-size: 9pt; }
</style>
