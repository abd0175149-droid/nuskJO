<template>
    <div class="print-wrapper" dir="rtl">
        <div class="no-print toolbar">
            <button @click="doPrint" class="print-btn">🖨️ طباعة</button>
            <a href="/accounting/trial-balance" class="back-btn">← العودة</a>
        </div>
        <div v-for="(page, pi) in pages" :key="pi" class="a4-landscape">
            <canvas v-if="templateUrl" :ref="el => setCanvas(el, pi)" class="pdf-bg"></canvas>
            <div v-else class="fallback-bg"></div>
            <div class="overlay">
                <template v-if="pi === 0">
                    <div v-if="!isHidden('title')" class="field" :style="elPos('title')"><span :style="elFont('title')">ميزان المراجعة</span></div>
                    <div v-if="!isHidden('period')" class="field" :style="elPos('period')"><span class="label">الفترة:</span><span class="value" :style="elFont('period')">{{ filters.from }} → {{ filters.to }}</span></div>
                    <div v-if="!isHidden('balance_status')" class="field" :style="elPos('balance_status')">
                        <span :style="elFont('balance_status')" :class="isBalanced ? 'green bold' : 'red bold'">{{ isBalanced ? '✅ متوازن' : '⚠️ غير متوازن' }}</span>
                    </div>
                </template>
                <div v-if="pi > 0" class="page-num">
                    <span>ميزان المراجعة</span>
                    <span class="mono">صفحة {{ pi + 1 }} / {{ pages.length }}</span>
                </div>

                <div v-if="!isHidden('data_table')" :style="pi === 0 ? elPos('data_table') : contTablePos">
                    <table class="print-tbl" :style="{ fontSize: (el('data_table').fontSize||8)+'pt', width: el('data_table').w?el('data_table').w+'mm':'100%' }">
                        <thead>
                            <tr><th rowspan="2">الكود</th><th rowspan="2">اسم الحساب</th><th colspan="2">رصيد أول المدة</th><th colspan="2">حركات الفترة</th><th colspan="2">رصيد آخر المدة</th></tr>
                            <tr><th>مدين</th><th>دائن</th><th>مدين</th><th>دائن</th><th>مدين</th><th>دائن</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in page.items" :key="a.id">
                                <td class="mono gold center bold" style="font-size:8pt">{{ a.code }}</td>
                                <td>{{ a.name }}</td>
                                <td class="mono center">{{ a.opening_debit>0?fmt(a.opening_debit):'—' }}</td>
                                <td class="mono center">{{ a.opening_credit>0?fmt(a.opening_credit):'—' }}</td>
                                <td class="mono center red bold">{{ a.period_debit>0?fmt(a.period_debit):'—' }}</td>
                                <td class="mono center green bold">{{ a.period_credit>0?fmt(a.period_credit):'—' }}</td>
                                <td class="mono center bold">{{ a.closing_debit>0?fmt(a.closing_debit):'—' }}</td>
                                <td class="mono center bold">{{ a.closing_credit>0?fmt(a.closing_credit):'—' }}</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="page.isLast"><tr class="total-row">
                            <td colspan="2" class="right bold">المجموع</td>
                            <td class="mono center">{{ fmt(totals.opening_debit) }}</td><td class="mono center">{{ fmt(totals.opening_credit) }}</td>
                            <td class="mono center red bold">{{ fmt(totals.period_debit) }}</td><td class="mono center green bold">{{ fmt(totals.period_credit) }}</td>
                            <td class="mono center bold">{{ fmt(totals.closing_debit) }}</td><td class="mono center bold">{{ fmt(totals.closing_credit) }}</td>
                        </tr></tfoot>
                    </table>
                </div>

                <template v-if="page.isLast">
                    <div v-if="!isHidden('signatures')" class="signatures" :style="sigPos(page, pi)">
                        <div class="sig-box"><div class="sig-label">المحاسب</div><div class="sig-line"></div></div>
                        <div class="sig-box"><div class="sig-label">المدير المالي</div><div class="sig-line"></div></div>
                    </div>
                    <template v-for="(pos, id) in customFields" :key="id"><div v-if="!pos.hidden" class="field" :style="elPos(id)"><span :style="elFont(id)" style="white-space:pre-wrap">{{ pos.text }}</span></div></template>
                </template>
            </div>
        </div>
    </div>
</template>
<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
const props = defineProps({ accounts: Array, filters: Object, templateUrl: String, layout: Object });
const fmt = (v) => Number(v||0).toLocaleString('en',{minimumFractionDigits:3});
const defaults = { title:{x:10,y:15,fontSize:14}, period:{x:120,y:15,fontSize:10}, balance_status:{x:200,y:15,fontSize:10}, data_table:{x:10,y:32,fontSize:8,w:277}, signatures:{x:10,y:180,fontSize:9,w:277} };
const el = (id) => props.layout?.elements?.[id] || defaults[id] || {x:10,y:10,fontSize:10};
const isHidden = (id) => !!(props.layout?.elements?.[id]?.hidden);
const elPos = (id) => { const p=el(id); return {position:'absolute',right:p.x+'mm',top:p.y+'mm',width:p.w?p.w+'mm':'auto'}; };
const elFont = (id) => { const p=el(id); return {fontSize:(p.fontSize||10)+'pt',color:p.color||undefined}; };

const rowsPerPage = computed(() => props.layout?.rowsPerPage || 15);
const pages = computed(() => {
    const items = props.accounts || [];
    const rpp = rowsPerPage.value;
    if (items.length <= rpp) return [{ items, startIdx: 0, isLast: true }];
    const result = [];
    for (let i = 0; i < items.length; i += rpp) {
        const chunk = items.slice(i, i + rpp);
        result.push({ items: chunk, startIdx: i, isLast: i + rpp >= items.length });
    }
    return result;
});
const contY = computed(() => props.layout?.contTableY || 20);
const contTablePos = computed(() => { const p=el('data_table'); return {position:'absolute',right:p.x+'mm',top:contY.value+'mm',width:p.w?p.w+'mm':'100%'}; });
const sigPos = (page, pi) => {
    const p=el('data_table'); const baseY = pi===0 ? p.y : contY.value;
    const y = baseY + 12 + (page.items.length * 5.5) + 10;
    const sp=el('signatures');
    return {position:'absolute',right:sp.x+'mm',top:y+'mm',width:(sp.w||277)+'mm'};
};

const totals = computed(() => {
    const t = {opening_debit:0,opening_credit:0,period_debit:0,period_credit:0,closing_debit:0,closing_credit:0};
    (props.accounts||[]).forEach(a => { Object.keys(t).forEach(k => t[k] += Number(a[k]||0)); }); return t;
});
const isBalanced = computed(() => Math.abs(totals.value.closing_debit - totals.value.closing_credit) < 0.01);
const customFields = computed(() => { if(!props.layout?.elements) return {}; const r={}; for(const[id,p] of Object.entries(props.layout.elements)){if(id.startsWith('custom_'))r[id]=p;} return r; });

const canvasRefs = {};
const setCanvas = (el, idx) => { if (el) canvasRefs[idx] = el; };
const renderPdf = async () => { if(!props.templateUrl) return; try { const pdfjsLib=await loadPdfJs(); const pdf=await pdfjsLib.getDocument(props.templateUrl).promise; const page=await pdf.getPage(1); const vp=page.getViewport({scale:2}); for(const[,canvas] of Object.entries(canvasRefs)){canvas.width=vp.width;canvas.height=vp.height;await page.render({canvasContext:canvas.getContext('2d'),viewport:vp}).promise;} }catch(e){console.error(e);} };
const loadPdfJs = () => new Promise((resolve,reject) => { if(window.pdfjsLib){resolve(window.pdfjsLib);return;} const s=document.createElement('script'); s.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js'; s.onload=()=>{window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';resolve(window.pdfjsLib);}; s.onerror=reject; document.head.appendChild(s); });
onMounted(async () => { await nextTick(); setTimeout(renderPdf, 400); });
const doPrint = () => window.print();
</script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=JetBrains+Mono:wght@400;700&display=swap');
.a4-landscape{width:297mm;height:210mm;position:relative;margin:0 auto;overflow:hidden;background:white;font-family:'Cairo',sans-serif;page-break-after:always}
.a4-landscape:last-child{page-break-after:auto}
.pdf-bg{position:absolute;top:0;left:0;width:100%;height:100%;z-index:0;pointer-events:none}.fallback-bg{position:absolute;inset:0;background:white;z-index:0}
.overlay{position:absolute;inset:0;z-index:1;direction:rtl}.field{font-family:'Cairo',sans-serif}
.label{color:#8b8680;font-size:8pt;font-weight:600;margin-left:3px}.value{font-weight:700;color:#1a1715}
.gold{color:#96722a}.mono{font-family:'JetBrains Mono',monospace}.red{color:#dc2626}.green{color:#16a34a}.bold{font-weight:700}
.page-num{position:absolute;top:8mm;right:10mm;left:10mm;display:flex;justify-content:space-between;font-size:8pt;color:#8b8680;border-bottom:1px solid #e8e4de;padding-bottom:4px}
.print-tbl{width:100%;border-collapse:collapse;font-family:'Cairo',sans-serif}
.print-tbl th{background:#2c2417;color:#dbb84d;padding:3px 5px;text-align:center;font-size:7pt;font-weight:700}
.print-tbl td{padding:2px 5px;border-bottom:.5px solid #e8e4de;font-size:7pt}.print-tbl .center{text-align:center}
.print-tbl .right{text-align:right}.print-tbl .total-row td{border-top:2px solid #2c2417;background:#f8f6f3}
.signatures{display:flex;justify-content:space-around}.sig-box{text-align:center;width:25%}
.sig-label{font-size:7.5pt;color:#8b8680;font-weight:600;margin-bottom:24px}.sig-line{border-top:1.5px solid #2c2417;padding-top:3px;font-size:6pt;color:#b0a89e}.sig-line::after{content:'التوقيع والختم'}
.toolbar{display:flex;gap:12px;justify-content:center;padding:12px;background:#f8f6f3;border-bottom:1px solid #e0dbd3}
.print-btn{padding:8px 24px;background:linear-gradient(135deg,#2c2417,#4a3c2e);color:#dbb84d;font-weight:700;border:none;border-radius:10px;cursor:pointer;font-family:'Cairo'}
.back-btn{padding:8px 20px;color:#5a5046;text-decoration:none;border:1.5px solid #d4cec4;border-radius:10px;font-family:'Cairo'}
@media screen{body{background:#e8e4de;margin:0}.a4-landscape{box-shadow:0 8px 30px rgba(0,0,0,.12);margin:20px auto;border-radius:4px}}
@media print{.no-print{display:none!important}body{margin:0;padding:0}.a4-landscape{margin:0;box-shadow:none;border-radius:0}@page{size:A4 landscape;margin:0}}
</style>
