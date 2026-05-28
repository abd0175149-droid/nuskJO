<template>
    <div class="print-wrapper" dir="rtl">
        <div class="no-print toolbar">
            <button @click="doPrint" class="print-btn">🖨️ طباعة</button>
            <a href="/accounting/chart-of-accounts" class="back-btn">← العودة</a>
        </div>
        <div v-for="(page, pi) in pages" :key="pi" class="a4-landscape">
            <canvas v-if="templateUrl" :ref="el => setCanvas(el, pi)" class="pdf-bg"></canvas>
            <div v-else class="fallback-bg"></div>
            <div class="overlay">
                <template v-if="pi === 0">
                    <div v-if="!isHidden('title')" class="field" :style="elPos('title')"><span :style="elFont('title')">دليل الحسابات (شجرة الحسابات)</span></div>
                    <div v-if="!isHidden('report_date')" class="field" :style="elPos('report_date')"><span class="label">التاريخ:</span><span class="value" :style="elFont('report_date')">{{ today }}</span></div>
                    <div v-if="!isHidden('accounts_count')" class="field" :style="elPos('accounts_count')"><span class="label">عدد الحسابات:</span><span class="value" :style="elFont('accounts_count')">{{ totalAccounts }}</span></div>
                </template>
                <div v-if="pi > 0" class="page-num">
                    <span>دليل الحسابات</span>
                    <span class="mono">صفحة {{ pi + 1 }} / {{ pages.length }}</span>
                </div>

                <div v-if="!isHidden('data_table')" :style="pi === 0 ? elPos('data_table') : contTablePos">
                    <table class="print-tbl" :style="{ fontSize: (el('data_table').fontSize||8)+'pt', width: el('data_table').w?el('data_table').w+'mm':'100%' }">
                        <thead><tr>
                            <th style="width:60px">الكود</th><th>اسم الحساب</th><th style="width:60px">النوع</th><th style="width:45px">العملة</th><th style="width:80px">الرصيد</th><th style="width:45px">الحالة</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="row in page.items" :key="row.id" :class="row.isParent ? 'parent-row' : ''">
                                <td class="mono gold center bold" style="font-size:8pt">{{ row.code }}</td>
                                <td :style="{ paddingRight: (8 + row.depth * 14) + 'px' }">
                                    <span v-if="row.isParent" class="bold">{{ row.depth===0?'📂':row.depth===1?'├─':'│ ├─' }} {{ row.name }}</span>
                                    <span v-else style="color:#444">{{ row.depth>0 ? '│ ' : '' }}└─ {{ row.name }}</span>
                                </td>
                                <td class="center"><span class="type-badge" :style="{color:typeColor(row.type)}">{{ typeLabel(row.type) }}</span></td>
                                <td class="mono center" style="font-size:8pt">{{ row.currency }}</td>
                                <td class="mono right bold" :class="row.isParent?'':(parseFloat(row.balance)>=0?'green':'red')">{{ row.isParent ? '—' : fmt(Math.abs(row.balance)) }}</td>
                                <td class="center" style="font-size:7pt"><span v-if="row.is_active" class="green">● نشط</span><span v-else class="red">○ معطل</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <template v-if="page.isLast">
                    <div v-if="!isHidden('signatures')" class="signatures" :style="sigPos(page, pi)">
                        <div class="sig-box"><div class="sig-label">المحاسب</div><div class="sig-line"></div></div>
                        <div class="sig-box"><div class="sig-label">المدقق الداخلي</div><div class="sig-line"></div></div>
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
const props = defineProps({ accounts: Array, templateUrl: String, layout: Object });
const today = new Date().toISOString().split('T')[0];
const fmt = (v) => Number(v||0).toLocaleString('en',{minimumFractionDigits:3,maximumFractionDigits:3});
const typeLabel = (t) => ({asset:'أصول',liability:'التزامات',equity:'ملكية',revenue:'إيرادات',expense:'مصروفات'}[t]||t);
const typeColor = (t) => ({asset:'#2563eb',liability:'#ea580c',equity:'#7c3aed',revenue:'#16a34a',expense:'#dc2626'}[t]||'#333');

const flattenTree = (accounts, depth=0, result=[]) => {
    (accounts||[]).forEach(a => {
        const hasChildren = a.children_recursive?.length > 0;
        result.push({ id:a.id, code:a.code, name:a.name, type:a.type, currency:a.currency, balance:a.balance, is_active:a.is_active, depth, isParent:hasChildren });
        if (hasChildren) flattenTree(a.children_recursive, depth+1, result);
    }); return result;
};
const flatRows = computed(() => flattenTree(props.accounts));
const totalAccounts = computed(() => flatRows.value.length);

const defaults = { title:{x:10,y:15,fontSize:14}, report_date:{x:200,y:15,fontSize:10}, accounts_count:{x:120,y:15,fontSize:10}, data_table:{x:10,y:32,fontSize:8,w:277}, signatures:{x:10,y:180,fontSize:9,w:277} };
const el = (id) => props.layout?.elements?.[id] || defaults[id] || {x:10,y:10,fontSize:10};
const isHidden = (id) => !!(props.layout?.elements?.[id]?.hidden);
const elPos = (id) => { const p=el(id); return {position:'absolute',right:p.x+'mm',top:p.y+'mm',width:p.w?p.w+'mm':'auto'}; };
const elFont = (id) => { const p=el(id); return {fontSize:(p.fontSize||10)+'pt',color:p.color||undefined}; };
const customFields = computed(() => { if(!props.layout?.elements) return {}; const r={}; for(const[id,p] of Object.entries(props.layout.elements)){if(id.startsWith('custom_'))r[id]=p;} return r; });

const rowsPerPage = computed(() => props.layout?.rowsPerPage || 20);
const pages = computed(() => {
    const items = flatRows.value;
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
    const y = baseY + 7 + (page.items.length * 5) + 10;
    const sp=el('signatures');
    return {position:'absolute',right:sp.x+'mm',top:y+'mm',width:(sp.w||277)+'mm'};
};

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
.print-tbl .right{text-align:left;direction:ltr}.parent-row td{background:#f9f9f9!important}
.type-badge{font-size:7pt;font-weight:700}
.signatures{display:flex;justify-content:space-around}.sig-box{text-align:center;width:25%}
.sig-label{font-size:7.5pt;color:#8b8680;font-weight:600;margin-bottom:24px}.sig-line{border-top:1.5px solid #2c2417;padding-top:3px;font-size:6pt;color:#b0a89e}.sig-line::after{content:'التوقيع والختم'}
.toolbar{display:flex;gap:12px;justify-content:center;padding:12px;background:#f8f6f3;border-bottom:1px solid #e0dbd3}
.print-btn{padding:8px 24px;background:linear-gradient(135deg,#2c2417,#4a3c2e);color:#dbb84d;font-weight:700;border:none;border-radius:10px;cursor:pointer;font-family:'Cairo'}
.back-btn{padding:8px 20px;color:#5a5046;text-decoration:none;border:1.5px solid #d4cec4;border-radius:10px;font-family:'Cairo'}
@media screen{body{background:#e8e4de;margin:0}.a4-landscape{box-shadow:0 8px 30px rgba(0,0,0,.12);margin:20px auto;border-radius:4px}}
@media print{.no-print{display:none!important}body{margin:0;padding:0}.a4-landscape{margin:0;box-shadow:none;border-radius:0}@page{size:A4 landscape;margin:0}.parent-row td{background:#f5f5f5!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
