<template>
    <div class="print-wrapper" dir="rtl">
        <div class="no-print toolbar">
            <button @click="doPrint" class="print-btn">🖨️ طباعة</button>
            <a href="/agents" class="back-btn">← العودة</a>
        </div>

        <div v-for="(page, pi) in pages" :key="pi" class="a4-landscape">
            <canvas v-if="templateUrl" :ref="el => setCanvas(el, pi)" class="pdf-bg"></canvas>
            <div v-else class="fallback-bg"></div>

            <div class="overlay">
                <!-- معلومات الوكيل - الصفحة الأولى فقط -->
                <template v-if="pi === 0">
                    <div v-if="!isHidden('title')" class="field" :style="elPos('title')">
                        <span :style="elFont('title')">كشف حساب وكيل</span>
                    </div>
                    <div v-if="!isHidden('entity_name')" class="field" :style="elPos('entity_name')">
                        <span class="label">الوكيل:</span>
                        <span class="value" :style="elFont('entity_name')">{{ agent.name }}</span>
                    </div>
                    <div v-if="!isHidden('entity_code')" class="field" :style="elPos('entity_code')">
                        <span class="label">الكود:</span>
                        <span class="value gold" :style="elFont('entity_code')">{{ agent.code }}</span>
                    </div>
                    <div v-if="!isHidden('period')" class="field" :style="elPos('period')">
                        <span class="label">الفترة:</span>
                        <span class="value" :style="elFont('period')">{{ filters.from }} → {{ filters.to }}</span>
                    </div>
                    <div class="field" :style="elPos('currency')">
                        <span class="label">العملة:</span>
                        <span class="value" :style="elFont('currency')">SAR</span>
                        <span v-if="agent.contact_person" class="label" style="margin-right:10px">جهة الاتصال:</span>
                        <span v-if="agent.contact_person" class="value">{{ agent.contact_person }}</span>
                    </div>
                </template>

                <!-- ترقيم الصفحات اللاحقة -->
                <div v-if="pi > 0" class="page-num">
                    <span>كشف حساب: {{ agent.name }}</span>
                    <span class="mono">صفحة {{ pi + 1 }} / {{ pages.length }}</span>
                </div>

                <!-- المحتوى حسب نوع الصفحة -->
                <div :style="pi === 0 ? elPos('data_table') : contTablePosStyle">

                    <!-- === جدول الحوالات === -->
                    <template v-if="page.section === 'transfers'">
                        <h4 class="section-title transfer-bg">💸 الحوالات (ما دفعناه للوكيل)</h4>
                        <table class="print-tbl" :style="tblStyle">
                            <thead><tr>
                                <th style="width:22px">#</th>
                                <th style="width:60px">التاريخ</th>
                                <th style="width:80px">رقم الحوالة</th>
                                <th style="width:75px">المبلغ SAR</th>
                                <th style="width:65px">التكلفة JOD</th>
                                <th style="width:55px">سعر الصرف</th>
                                <th style="width:55px">طريقة الدفع</th>
                                <th>ملاحظات</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="(t, i) in page.items" :key="t.id" :class="t.is_reversed ? 'reversed-row' : ''">
                                    <td class="center">{{ page.startIdx + i + 1 }}</td>
                                    <td class="mono center">{{ t.date }}</td>
                                    <td class="mono center gold bold">{{ t.transfer_number }}</td>
                                    <td class="mono right bold" :class="t.amount_sar < 0 ? 'red' : ''">{{ fmtSar(t.amount_sar) }}</td>
                                    <td class="mono right">{{ fmtJod(t.cost_jod) }}</td>
                                    <td class="mono center">{{ t.exchange_rate }}</td>
                                    <td class="center"><span class="method-tag">{{ t.payment_method }}</span></td>
                                    <td class="details-cell">{{ t.notes }}</td>
                                </tr>
                                <tr v-if="!page.items.length"><td colspan="8" class="empty">لا يوجد حوالات</td></tr>
                            </tbody>
                            <tfoot v-if="page.showTotal">
                                <tr class="total-row">
                                    <td colspan="3" class="right bold">إجمالي الحوالات ({{ summary.transfers_count }})</td>
                                    <td class="mono right bold gold">{{ fmtSar(summary.transfers_total) }}</td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </template>

                    <!-- === جدول الفواتير (الخدمات) === -->
                    <template v-if="page.section === 'invoices'">
                        <h4 class="section-title invoice-bg">📄 الفواتير (خدمات مطلوبة من الوكيل)</h4>
                        <table class="print-tbl" :style="tblStyle">
                            <thead><tr>
                                <th style="width:22px">#</th>
                                <th style="width:60px">التاريخ</th>
                                <th style="width:80px">رقم الفاتورة</th>
                                <th style="width:70px">العميل</th>
                                <th>التفاصيل</th>
                                <th style="width:75px">التكلفة SAR</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="(inv, i) in page.items" :key="inv.id" :class="inv.is_reversed ? 'reversed-row' : ''">
                                    <td class="center">{{ page.startIdx + i + 1 }}</td>
                                    <td class="mono center">{{ inv.date }}</td>
                                    <td class="mono center gold bold">{{ inv.invoice_number }}</td>
                                    <td class="center">{{ inv.client_name }}</td>
                                    <td class="details-cell">{{ inv.details }}</td>
                                    <td class="mono right bold" :class="inv.amount < 0 ? 'red' : ''">{{ fmtSar(inv.amount) }}</td>
                                </tr>
                                <tr v-if="!page.items.length"><td colspan="6" class="empty">لا يوجد فواتير</td></tr>
                            </tbody>
                            <tfoot v-if="page.showTotal">
                                <tr class="total-row">
                                    <td colspan="5" class="right bold">إجمالي الفواتير ({{ summary.invoices_count }})</td>
                                    <td class="mono right bold gold">{{ fmtSar(summary.invoices_total) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </template>

                    <!-- === جدول المخالفات === -->
                    <template v-if="page.section === 'violations'">
                        <h4 class="section-title violation-bg">⚠️ المخالفات</h4>
                        <table class="print-tbl" :style="tblStyle">
                            <thead><tr>
                                <th style="width:22px">#</th>
                                <th style="width:60px">التاريخ</th>
                                <th style="width:80px">رقم المخالفة</th>
                                <th style="width:70px">النوع</th>
                                <th style="width:80px">صاحب الجواز</th>
                                <th>الوصف</th>
                                <th style="width:75px">التكلفة SAR</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="(v, i) in page.items" :key="v.id" :class="v.is_reversed ? 'reversed-row' : ''">
                                    <td class="center">{{ page.startIdx + i + 1 }}</td>
                                    <td class="mono center">{{ v.date }}</td>
                                    <td class="mono center gold bold">{{ v.violation_number }}</td>
                                    <td class="center">{{ v.type }}</td>
                                    <td class="center">{{ v.passport_name }}</td>
                                    <td class="details-cell">{{ v.description }}</td>
                                    <td class="mono right bold red">{{ fmtSar(v.amount) }}</td>
                                </tr>
                                <tr v-if="!page.items.length"><td colspan="7" class="empty">لا يوجد مخالفات</td></tr>
                            </tbody>
                            <tfoot v-if="page.showTotal">
                                <tr class="total-row">
                                    <td colspan="6" class="right bold">إجمالي المخالفات ({{ summary.violations_count }})</td>
                                    <td class="mono right bold red">{{ fmtSar(summary.violations_total) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </template>

                    <!-- === جدول الملخص === -->
                    <template v-if="page.hasSummary">
                        <div :style="{ marginTop: page.section !== 'summary' ? '10px' : '0' }">
                        <h4 class="section-title summary-bg">📊 ملخص حساب الوكيل</h4>
                        <table class="print-tbl summary-tbl" :style="{ fontSize: '9pt', width: '277mm' }">
                            <thead><tr>
                                <th>البيان</th>
                                <th style="width:60px">العدد</th>
                                <th style="width:100px">المبلغ (SAR)</th>
                            </tr></thead>
                            <tbody>
                                <tr>
                                    <td class="bold">💸 إجمالي الحوالات (ما دفعناه للوكيل)</td>
                                    <td class="mono center bold">{{ summary.transfers_count }}</td>
                                    <td class="mono right bold green">{{ fmtSar(summary.transfers_total) }}</td>
                                </tr>
                                <tr>
                                    <td class="bold">📄 إجمالي الفواتير (تكلفة الخدمات)</td>
                                    <td class="mono center bold">{{ summary.invoices_count }}</td>
                                    <td class="mono right bold">{{ fmtSar(summary.invoices_total) }}</td>
                                </tr>
                                <tr>
                                    <td class="bold">⚠️ إجمالي المخالفات</td>
                                    <td class="mono center bold">{{ summary.violations_count }}</td>
                                    <td class="mono right bold red">{{ fmtSar(summary.violations_total) }}</td>
                                </tr>
                                <tr class="balance-row">
                                    <td class="bold" style="font-size:10pt">
                                        {{ summary.balance > 0 ? '🟢 رصيد متبقي لدى الوكيل' : summary.balance < 0 ? '🔴 مستحق للوكيل' : '✅ الحساب مُسوّى' }}
                                    </td>
                                    <td class="center">—</td>
                                    <td class="mono right bold" :class="summary.balance > 0 ? 'green' : summary.balance < 0 ? 'red' : ''" style="font-size:11pt">
                                        {{ fmtSar(Math.abs(summary.balance)) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </template>
                </div>

                <!-- التوقيعات + حقول مخصصة -->
                <template v-if="page.isLast">
                    <div v-if="!isHidden('signatures')" class="signatures" :style="sigPosCalc(page, pi)">
                        <div class="sig-box"><div class="sig-label">المحاسب</div><div class="sig-line"></div></div>
                        <div class="sig-box"><div class="sig-label">المدير المالي</div><div class="sig-line"></div></div>
                        <div class="sig-box"><div class="sig-label">الوكيل</div><div class="sig-line"></div></div>
                    </div>
                    <template v-for="(pos, id) in customFields" :key="id">
                        <div v-if="!pos.hidden" class="field" :style="elPos(id)">
                            <span :style="elFont(id)" style="white-space:pre-wrap;">{{ replaceVars(pos.text || '') }}</span>
                        </div>
                    </template>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';

const props = defineProps({
    agent: Object, transfers: Array, invoices: Array, violations: Array,
    summary: Object, filters: Object, templateUrl: String, layout: Object,
});

const fmtSar = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 2 });
const fmtJod = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3 });

const defaults = {
    title: { x: 10, y: 15, fontSize: 14 }, entity_name: { x: 10, y: 28, fontSize: 12 },
    entity_code: { x: 120, y: 28, fontSize: 10 }, period: { x: 200, y: 28, fontSize: 10 },
    currency: { x: 200, y: 15, fontSize: 10 },
    data_table: { x: 10, y: 45, fontSize: 8, w: 277 },
    signatures: { x: 10, y: 180, fontSize: 9, w: 277 },
};

const el = (id) => props.layout?.elements?.[id] || defaults[id] || { x: 10, y: 10, fontSize: 10 };
const isHidden = (id) => !!(props.layout?.elements?.[id]?.hidden);
const elPos = (id) => { const p = el(id); return { position: 'absolute', right: p.x + 'mm', top: p.y + 'mm', width: p.w ? p.w + 'mm' : 'auto' }; };
const elFont = (id) => { const p = el(id); return { fontSize: (p.fontSize || 10) + 'pt', color: p.color || undefined }; };
const tblStyle = computed(() => ({ fontSize: (el('data_table').fontSize || 8) + 'pt', width: el('data_table').w ? el('data_table').w + 'mm' : '100%' }));

const rowsPerPage = computed(() => props.layout?.rowsPerPage || 12);
const contY = computed(() => props.layout?.contTableY || 20);
const contTablePosStyle = computed(() => {
    const p = el('data_table');
    return { position: 'absolute', right: p.x + 'mm', top: contY.value + 'mm', width: p.w ? p.w + 'mm' : '100%' };
});

// بناء الصفحات: حوالات → فواتير → مخالفات → ملخص
function buildSection(data, sectionName, rpp) {
    const result = [];
    if (!data || data.length === 0) {
        result.push({ section: sectionName, items: [], startIdx: 0, showTotal: true, isLast: false });
    } else {
        for (let i = 0; i < data.length; i += rpp) {
            const chunk = data.slice(i, i + rpp);
            result.push({ section: sectionName, items: chunk, startIdx: i, showTotal: i + rpp >= data.length, isLast: false });
        }
    }
    return result;
}

const pages = computed(() => {
    const rpp = rowsPerPage.value;
    const result = [
        ...buildSection(props.transfers, 'transfers', rpp),
        ...buildSection(props.invoices, 'invoices', rpp),
        ...buildSection(props.violations, 'violations', rpp),
    ];

    const lastPage = result[result.length - 1];
    if (lastPage && lastPage.items.length <= rpp - 6) {
        lastPage.hasSummary = true;
        lastPage.isLast = true;
    } else {
        result.push({ section: 'summary', items: [], startIdx: 0, showTotal: false, isLast: true, hasSummary: true });
    }
    return result;
});

const sigPosCalc = (page, pi) => {
    const p = el('data_table');
    const baseY = pi === 0 ? p.y : contY.value;
    const rowH = 5.5, headerH = 7, sectionH = 6;
    let tableRows = page.items.length + (page.showTotal ? 1 : 0);
    if (page.hasSummary) tableRows += 7;
    const y = baseY + sectionH + headerH + (tableRows * rowH) + 15;
    const sp = el('signatures');
    return { position: 'absolute', right: sp.x + 'mm', top: y + 'mm', width: (sp.w || 277) + 'mm' };
};

const customFields = computed(() => {
    if (!props.layout?.elements) return {};
    const r = {};
    for (const [id, p] of Object.entries(props.layout.elements)) {
        if (id.startsWith('custom_')) r[id] = p;
    }
    return r;
});
const replaceVars = (text) => {
    const a = props.agent;
    const map = {
        '{{اسم_الحساب}}': a?.name || '', '{{كود_الحساب}}': a?.code || '',
        '{{اسم_الوكيل}}': a?.name || '', '{{كود_الوكيل}}': a?.code || '',
        '{{جهة_الاتصال}}': a?.contact_person || '', '{{هاتف_الوكيل}}': a?.phone || '',
        '{{الفترة}}': `${props.filters?.from} → ${props.filters?.to}`,
    };
    let r = text;
    for (const [k, v] of Object.entries(map)) r = r.replaceAll(k, v);
    return r;
};

// PDF rendering
const canvasRefs = {};
const setCanvas = (el, idx) => { if (el) canvasRefs[idx] = el; };
const renderPdf = async () => {
    if (!props.templateUrl) return;
    try {
        const pdfjsLib = await loadPdfJs();
        const pdf = await pdfjsLib.getDocument(props.templateUrl).promise;
        const pdfPage = await pdf.getPage(1);
        const vp = pdfPage.getViewport({ scale: 2 });
        for (const [, canvas] of Object.entries(canvasRefs)) {
            canvas.width = vp.width; canvas.height = vp.height;
            await pdfPage.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
        }
    } catch (e) { console.error('PDF:', e); }
};
const loadPdfJs = () => new Promise((resolve, reject) => {
    if (window.pdfjsLib) { resolve(window.pdfjsLib); return; }
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    s.onload = () => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'; resolve(window.pdfjsLib); };
    s.onerror = reject; document.head.appendChild(s);
});
onMounted(async () => { await nextTick(); setTimeout(renderPdf, 400); });
const doPrint = () => window.print();
</script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=JetBrains+Mono:wght@400;700&display=swap');
.a4-landscape { width: 297mm; height: 210mm; position: relative; margin: 0 auto; overflow: hidden; background: white; font-family: 'Cairo', sans-serif; page-break-after: always; }
.a4-landscape:last-child { page-break-after: auto; }
.pdf-bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; }
.fallback-bg { position: absolute; inset: 0; background: white; z-index: 0; }
.overlay { position: absolute; inset: 0; z-index: 1; direction: rtl; }
.field { font-family: 'Cairo', sans-serif; }
.label { color: #8b8680; font-size: 8pt; font-weight: 600; margin-left: 3px; }
.value { font-weight: 700; color: #1a1715; }
.value.gold, .gold { color: #96722a; }
.mono { font-family: 'JetBrains Mono', monospace; }
.red { color: #dc2626; } .green { color: #16a34a; } .bold { font-weight: 700; }
.page-num { position: absolute; top: 8mm; right: 10mm; left: 10mm; display: flex; justify-content: space-between; font-size: 8pt; color: #8b8680; border-bottom: 1px solid #e8e4de; padding-bottom: 4px; }

/* Section titles */
.section-title { font-size: 9pt; font-weight: 700; margin: 0 0 4px; padding: 4px 10px; border-radius: 5px; font-family: 'Cairo'; }
.transfer-bg { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.invoice-bg { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.violation-bg { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.summary-bg { background: #faf5ff; color: #7c3aed; border: 1px solid #ddd6fe; }

/* Tables */
.print-tbl { width: 100%; border-collapse: collapse; font-family: 'Cairo', sans-serif; }
.print-tbl th { background: #2c2417; color: #dbb84d; padding: 4px 6px; text-align: center; font-size: 7pt; font-weight: 700; }
.print-tbl td { padding: 3px 5px; border-bottom: 0.5px solid #e8e4de; font-size: 7pt; }
.print-tbl .center { text-align: center; }
.print-tbl .right { text-align: left; direction: ltr; }
.print-tbl .total-row td { border-top: 2px solid #2c2417; background: #f8f6f3; font-size: 7.5pt; }
.print-tbl .empty { text-align: center; color: #999; padding: 12px; }
.details-cell { max-width: 130mm; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 6.5pt; color: #555; }
.reversed-row td { background: #fef2f2 !important; }
.reversed-row td:last-child::before { content: '↩ '; color: #dc2626; }
.method-tag { font-size: 6pt; padding: 1px 5px; border-radius: 8px; background: #f3f4f6; font-weight: 600; }

/* Summary table */
.summary-tbl td, .summary-tbl th { padding: 6px 10px; font-size: 8.5pt; }
.balance-row td { border-top: 2.5px double #2c2417; background: #fffbeb; padding: 8px 10px; }

/* Signatures */
.signatures { display: flex; justify-content: space-around; }
.sig-box { text-align: center; width: 25%; }
.sig-label { font-size: 7.5pt; color: #8b8680; font-weight: 600; margin-bottom: 24px; }
.sig-line { border-top: 1.5px solid #2c2417; padding-top: 3px; font-size: 6pt; color: #b0a89e; }
.sig-line::after { content: 'التوقيع والختم'; }

/* Toolbar */
.toolbar { display: flex; gap: 12px; justify-content: center; padding: 12px; background: #f8f6f3; border-bottom: 1px solid #e0dbd3; }
.print-btn { padding: 8px 24px; background: linear-gradient(135deg, #2c2417, #4a3c2e); color: #dbb84d; font-weight: 700; border: none; border-radius: 10px; cursor: pointer; font-family: 'Cairo'; }
.back-btn { padding: 8px 20px; color: #5a5046; text-decoration: none; border: 1.5px solid #d4cec4; border-radius: 10px; font-family: 'Cairo'; }

@media screen { body { background: #e8e4de; margin: 0; } .a4-landscape { box-shadow: 0 8px 30px rgba(0,0,0,.12); margin: 20px auto; border-radius: 4px; } }
@media print {
    .no-print { display: none !important; }
    body { margin: 0; padding: 0; }
    .a4-landscape { margin: 0; box-shadow: none; border-radius: 0; }
    @page { size: A4 landscape; margin: 0; }
    .reversed-row td { background: #fef2f2 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .balance-row td { background: #fffbeb !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .transfer-bg, .invoice-bg, .violation-bg, .summary-bg { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .print-tbl th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .total-row td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
