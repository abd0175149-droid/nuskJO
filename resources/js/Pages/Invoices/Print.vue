<template>
    <div class="print-wrapper" dir="rtl">
        <!-- شريط الأدوات (يختفي عند الطباعة) -->
        <div class="no-print toolbar">
            <button @click="doPrint" class="print-btn">🖨️ طباعة</button>
            <a href="/invoices" class="back-btn">← العودة</a>
        </div>

        <!-- صفحات A4 -->
        <div v-for="(page, pi) in pages" :key="pi" class="a4-page">
            <canvas v-if="templateUrl" :ref="el => setCanvas(el, pi)" class="pdf-bg"></canvas>
            <div v-else class="fallback-bg"></div>

            <div class="overlay">
                <!-- العناصر الثابتة (تظهر في كل الصفحات أو الأولى فقط) -->
                <template v-if="pi === 0">
                    <!-- عنوان -->
                    <div v-if="!isHidden('title')" class="field" :style="elPos('title')">
                        <span :style="elFont('title')">فاتورة مبيعات</span>
                    </div>

                    <!-- رقم الفاتورة -->
                    <div v-if="!isHidden('invoice_number')" class="field" :style="elPos('invoice_number')">
                        <span class="label">رقم الفاتورة:</span>
                        <span class="value gold" :style="elFont('invoice_number')">{{ invoice.invoice_number }}</span>
                    </div>

                    <!-- التاريخ -->
                    <div v-if="!isHidden('invoice_date')" class="field" :style="elPos('invoice_date')">
                        <span class="label">التاريخ:</span>
                        <span class="value" :style="elFont('invoice_date')">{{ formatDate(invoice.invoice_date) }}</span>
                    </div>

                    <!-- الحالة -->
                    <div v-if="!isHidden('status')" class="field" :style="elPos('status')">
                        <span class="label">الحالة:</span>
                        <span class="value" :class="'status-'+invoice.status" :style="elFont('status')">{{ statusLabels[invoice.status] }}</span>
                    </div>

                    <!-- اسم العميل -->
                    <div v-if="!isHidden('client_name')" class="field" :style="elPos('client_name')">
                        <span class="label">العميل:</span>
                        <span class="value client-name" :style="elFont('client_name')">{{ invoice.client?.name }}</span>
                    </div>
                </template>

                <!-- جدول البنود (يتكرر حسب الصفحة) -->
                <div v-if="!isHidden('items_table')" class="table-area" :style="pi === 0 ? elPos('items_table') : contTablePosInv">
                    <table class="inv-table" :style="{ ...tableWidth, ...tableColors }">
                        <thead>
                            <tr>
                                <th class="col-num">#</th>
                                <th class="col-type">نوع الخدمة</th>
                                <th class="col-desc">وصف الخدمة</th>
                                <th class="col-qty">الكمية</th>
                                <th class="col-price">سعر البيع</th>
                                <th class="col-total">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, i) in page.items" :key="i">
                                <td class="center">{{ page.startIdx + i + 1 }}</td>
                                <td>{{ item.item_type === 'service' ? 'خدمة' : 'مخالفة' }}</td>
                                <td>{{ item.description }}</td>
                                <td class="center mono">{{ item.quantity }}</td>
                                <td class="mono ltr">{{ fmt(item.sell_price_jod) }}</td>
                                <td class="mono ltr bold">{{ fmt(item.total_sell_jod) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- الإجمالي (آخر صفحة فقط) -->
                <template v-if="page.isLast">
                    <div v-if="!isHidden('total')" class="total-box" :style="totalPos(page, pi)">
                        <div class="total-row" :style="elStyle('total')">
                            <span>الإجمالي:</span>
                            <span class="total-amount" :style="elFont('total')">{{ fmt(invoice.total_jod) }} JOD</span>
                        </div>
                    </div>

                    <!-- التوقيعات -->
                    <div v-if="!isHidden('signatures')" class="signatures" :style="sigPos(page, pi)">
                        <div class="sig-box"><div class="sig-label">المحاسب</div><div class="sig-line"></div></div>
                        <div class="sig-box"><div class="sig-label">المدير المالي</div><div class="sig-line"></div></div>
                        <div class="sig-box"><div class="sig-label">العميل</div><div class="sig-line"></div></div>
                    </div>
                </template>

                <!-- الحقول المخصصة (custom text) -->
                <template v-for="(pos, id) in customFields" :key="id">
                    <div v-if="!pos.hidden" class="field" :style="elPos(id)">
                        <span :style="elFont(id)" style="white-space: pre-wrap;">{{ replaceVars(pos.text || '') }}</span>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';

const props = defineProps({ invoice: Object, templateUrl: String, layout: Object });

const statusLabels = { pending: 'معلقة', approved: 'معتمدة', rejected: 'مرفوضة', editing: 'تحت التعديل' };
const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3 });
const formatDate = (d) => d ? new Date(d).toLocaleDateString('ar-SA', { year: 'numeric', month: 'long', day: 'numeric' }) : '';

// المواقع الافتراضية (mm)
const defaults = {
    title: { x: 10, y: 30, fontSize: 16 },
    invoice_number: { x: 10, y: 45, fontSize: 11 },
    invoice_date: { x: 80, y: 45, fontSize: 10 },
    status: { x: 150, y: 45, fontSize: 10 },
    client_name: { x: 10, y: 58, fontSize: 12 },
    items_table: { x: 10, y: 72, fontSize: 9, w: 190 },
    total: { x: 10, y: 200, fontSize: 13 },
    signatures: { x: 10, y: 250, fontSize: 9, w: 190 },
};

const el = (id) => props.layout?.elements?.[id] || defaults[id] || { x: 10, y: 10, fontSize: 10 };
const isHidden = (id) => !!(props.layout?.elements?.[id]?.hidden);
const rowsPerPage = computed(() => props.layout?.rowsPerPage || 10);

// الحقول المخصصة
const customFields = computed(() => {
    if (!props.layout?.elements) return {};
    const result = {};
    for (const [id, pos] of Object.entries(props.layout.elements)) {
        if (id.startsWith('custom_')) result[id] = pos;
    }
    return result;
});

const replaceVars = (text) => {
    const inv = props.invoice;
    const map = {
        '{{اسم_العميل}}': inv.client?.name || '',
        '{{كود_العميل}}': inv.client?.code || '',
        '{{هاتف_العميل}}': inv.client?.phone || '',
        '{{رقم_الفاتورة}}': inv.invoice_number || '',
        '{{التاريخ}}': formatDate(inv.invoice_date),
        '{{الاجمالي}}': fmt(inv.total_jod) + ' JOD',
        '{{الحالة}}': statusLabels[inv.status] || inv.status,
    };
    let result = text;
    for (const [key, val] of Object.entries(map)) {
        result = result.replaceAll(key, val);
    }
    return result;
};

const elPos = (id) => {
    const p = el(id);
    return { position: 'absolute', right: p.x + 'mm', top: p.y + 'mm', width: p.w ? p.w + 'mm' : 'auto' };
};

const elFont = (id) => {
    const p = el(id);
    return { fontSize: (p.fontSize || 10) + 'pt', color: p.color || undefined };
};

const elStyle = (id) => {
    const p = el(id);
    const s = {};
    if (p.bgColor) s.background = p.bgColor;
    if (p.color) s.color = p.color;
    if (p.w) s.width = p.w + 'mm';
    return s;
};

const tableWidth = computed(() => {
    const p = el('items_table');
    return p.w ? { width: p.w + 'mm' } : {};
});

const tableColors = computed(() => {
    const p = el('items_table');
    return {
        '--th-bg': p.thBg || '#2c2417',
        '--th-color': p.thColor || '#e8dcc8',
        '--th-border': p.thBorder || '#b8960b',
        '--td-even': p.tdEven || '#faf8f5',
    };
});

// تقسيم البنود على صفحات
const pages = computed(() => {
    const items = props.invoice?.items || [];
    const rpp = rowsPerPage.value;
    if (items.length <= rpp) {
        return [{ items, startIdx: 0, isLast: true }];
    }
    const result = [];
    for (let i = 0; i < items.length; i += rpp) {
        const chunk = items.slice(i, i + rpp);
        result.push({ items: chunk, startIdx: i, isLast: i + rpp >= items.length });
    }
    return result;
});
// موقع الجدول في الصفحات اللاحقة
const contY = computed(() => props.layout?.contTableY || el('items_table').y);
const contTablePosInv = computed(() => {
    const p = el('items_table');
    return { position: 'absolute', right: p.x + 'mm', top: contY.value + 'mm', width: p.w ? p.w + 'mm' : 'auto' };
});

// موقع الإجمالي (أسفل الجدول في آخر صفحة)
const totalPos = (page, pi) => {
    const p = el('items_table');
    const rowH = 7;
    const headerH = 8;
    const baseY = pi === 0 ? p.y : contY.value;
    const y = baseY + headerH + (page.items.length * rowH) + 4;
    const tp = el('total');
    return { position: 'absolute', right: tp.x + 'mm', top: y + 'mm', width: (tp.w || p.w || 190) + 'mm' };
};

const sigPos = (page, pi) => {
    const p = el('items_table');
    const rowH = 7;
    const headerH = 8;
    const baseY = pi === 0 ? p.y : contY.value;
    const y = baseY + headerH + (page.items.length * rowH) + 25;
    const sp = el('signatures');
    return { position: 'absolute', right: sp.x + 'mm', top: y + 'mm', width: (sp.w || 190) + 'mm' };
};

// تحميل PDF
const canvases = {};
const setCanvas = (el, idx) => { if (el) canvases[idx] = el; };

const renderPdf = async () => {
    if (!props.templateUrl) return;
    try {
        const pdfjsLib = await loadPdfJs();
        const pdf = await pdfjsLib.getDocument(props.templateUrl).promise;
        const pdfPage = await pdf.getPage(1);
        const scale = 2;
        const viewport = pdfPage.getViewport({ scale });

        await nextTick();
        for (const [idx, canvas] of Object.entries(canvases)) {
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            await pdfPage.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        }
    } catch (e) { console.error('PDF error:', e); }
};

const loadPdfJs = () => new Promise((resolve, reject) => {
    if (window.pdfjsLib) { resolve(window.pdfjsLib); return; }
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    s.onload = () => {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        resolve(window.pdfjsLib);
    };
    s.onerror = reject;
    document.head.appendChild(s);
});

onMounted(() => { setTimeout(renderPdf, 300); });
const doPrint = () => window.print();
</script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&family=JetBrains+Mono:wght@400;700&display=swap');

.a4-page {
    width: 210mm; height: 297mm;
    position: relative; margin: 0 auto; overflow: hidden;
    background: white;
    font-family: 'Cairo', sans-serif;
    page-break-after: always;
}
.a4-page:last-child { page-break-after: auto; }
.pdf-bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; }
.fallback-bg { position: absolute; inset: 0; background: white; z-index: 0; }
.overlay { position: absolute; inset: 0; z-index: 1; direction: rtl; }

/* Typography */
.field { font-family: 'Cairo', sans-serif; letter-spacing: -0.01em; }
.label { color: #8b8680; font-size: 8pt; font-weight: 600; margin-left: 3px; }
.value { font-weight: 700; color: #1a1715; }
.value.gold { color: #96722a; font-family: 'JetBrains Mono', monospace; letter-spacing: 0.5px; }
.value.client-name { color: #1a1715; letter-spacing: 0.3px; }
.value.status-approved { color: #0d7a3e; background: #e8f5ee; padding: 1px 8px; border-radius: 20px; font-size: 8pt; }
.value.status-pending { color: #8a6d0b; background: #fef9e7; padding: 1px 8px; border-radius: 20px; font-size: 8pt; }
.value.status-rejected { color: #b91c1c; background: #fef2f2; padding: 1px 8px; border-radius: 20px; font-size: 8pt; }

/* ═══════ الجدول العصري ═══════ */
.table-area { position: absolute; }
.inv-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 8.5pt;
    font-family: 'Cairo', sans-serif;
    border-radius: 4px;
    overflow: hidden;
}

/* Header — ألوان ديناميكية عبر CSS variables */
.inv-table th {
    background: var(--th-bg, #2c2417);
    color: var(--th-color, #e8dcc8);
    padding: 5px 8px;
    text-align: center;
    font-weight: 700;
    font-size: 7.5pt;
    letter-spacing: 0.3px;
    border: none;
    border-bottom: 2px solid var(--th-border, #b8960b);
}
.inv-table th:first-child { border-radius: 0 4px 0 0; }
.inv-table th:last-child { border-radius: 4px 0 0 0; }

/* Rows */
.inv-table td {
    padding: 4px 8px;
    font-size: 8pt;
    color: #3d3227;
    text-align: center;
    border: none;
    border-bottom: 1px solid #ede9e3;
}
.inv-table tbody tr:nth-child(odd) td { background: #ffffff; }
.inv-table tbody tr:nth-child(even) td { background: var(--td-even, #faf8f5); }
.inv-table tbody tr:last-child td { border-bottom: 1.5px solid #d4cec4; }
.inv-table tbody tr:last-child td:first-child { border-radius: 0 0 4px 0; }
.inv-table tbody tr:last-child td:last-child { border-radius: 0 0 0 4px; }

/* Cell types */
.inv-table .center { text-align: center; }
.inv-table .mono { font-family: 'JetBrains Mono', monospace; font-size: 7.5pt; letter-spacing: 0.3px; }
.inv-table .ltr { direction: ltr; }
.inv-table .bold { font-weight: 700; color: #1a1715; }

/* Column widths */
.col-num { width: 5%; text-align: center; }
.col-type { width: 13%; }
.col-desc { width: 37%; }
.col-qty { width: 9%; }
.col-price { width: 18%; }
.col-total { width: 18%; }

.inv-table td:first-child { font-family: 'JetBrains Mono', monospace; font-size: 7pt; color: #a09585; font-weight: 600; }

/* ═══════ الإجمالي ═══════ */
.total-box { position: absolute; }
.total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 12px;
    background: #f5f0e8;
    border: 1.5px solid #d4c9a8;
    border-radius: 4px;
    font-size: 11pt;
    font-weight: 900;
    color: #2c2417;
    font-family: 'Cairo', sans-serif;
}
.total-amount {
    color: #96722a;
    font-family: 'JetBrains Mono', monospace;
    font-size: 12pt;
    letter-spacing: 0.5px;
}

/* ═══════ التوقيعات ═══════ */
.signatures { display: flex; justify-content: space-between; position: absolute; }
.sig-box { text-align: center; width: 28%; }
.sig-label {
    font-size: 7.5pt;
    color: #8b8680;
    font-weight: 600;
    letter-spacing: 0.3px;
    margin-bottom: 28px;
    font-family: 'Cairo', sans-serif;
}
.sig-line {
    border-top: 1.5px solid #2c2417;
    padding-top: 4px;
    font-size: 6.5pt;
    color: #b0a89e;
    font-family: 'Cairo', sans-serif;
}
.sig-line::after { content: 'التوقيع والختم'; }

/* ═══════ Toolbar ═══════ */
.toolbar {
    display: flex; gap: 12px; justify-content: center;
    padding: 16px;
    background: linear-gradient(135deg, #f8f6f3, #ede9e3);
    border-bottom: 1px solid #e0dbd3;
}
.print-btn {
    padding: 10px 28px;
    background: linear-gradient(135deg, #2c2417, #4a3c2e);
    color: #dbb84d;
    font-weight: 700;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    font-family: 'Cairo', sans-serif;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 8px rgba(44,36,23,0.2);
    transition: all 0.2s;
}
.print-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(44,36,23,0.3); }
.back-btn {
    padding: 10px 24px; color: #5a5046;
    text-decoration: none;
    border: 1.5px solid #d4cec4;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Cairo', sans-serif;
    transition: all 0.2s;
}
.back-btn:hover { background: #f0ece4; border-color: #b0a89e; }

/* ═══════ Screen / Print ═══════ */
@media screen {
    body { background: #e8e4de; margin: 0; }
    .a4-page { box-shadow: 0 8px 30px rgba(0,0,0,.12); margin: 24px auto; border-radius: 4px; }
}
@media print {
    .no-print { display: none !important; }
    body { margin: 0; padding: 0; }
    .a4-page { margin: 0; box-shadow: none; border-radius: 0; }
    @page { size: A4; margin: 0; }
    .inv-table th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inv-table td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .total-row { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
