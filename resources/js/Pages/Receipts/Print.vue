<template>
    <div class="print-wrapper" dir="rtl">
        <div class="no-print toolbar">
            <button @click="doPrint" class="print-btn">🖨️ طباعة</button>
            <a href="/receipts" class="back-btn">← العودة</a>
        </div>

        <div class="a4-page">
            <canvas v-if="templateUrl" ref="pdfCanvas" class="pdf-bg"></canvas>
            <div v-else class="fallback-bg"></div>

            <div class="overlay">
                <div v-if="!isHidden('title')" class="field" :style="elPos('title')">
                    <span :style="elFont('title')">سند قبض</span>
                </div>

                <div v-if="!isHidden('receipt_number')" class="field" :style="elPos('receipt_number')">
                    <span class="label">رقم السند:</span>
                    <span class="value gold" :style="elFont('receipt_number')">{{ receipt.receipt_number }}</span>
                </div>

                <div v-if="!isHidden('receipt_date')" class="field" :style="elPos('receipt_date')">
                    <span class="label">التاريخ:</span>
                    <span class="value" :style="elFont('receipt_date')">{{ formatDate(receipt.receipt_date) }}</span>
                </div>

                <div v-if="!isHidden('status')" class="field" :style="elPos('status')">
                    <span class="label">الحالة:</span>
                    <span class="value" :class="'status-'+receipt.status" :style="elFont('status')">{{ statusLabels[receipt.status] }}</span>
                </div>

                <div v-if="!isHidden('client_name')" class="field" :style="elPos('client_name')">
                    <span class="label">العميل:</span>
                    <span class="value client-name" :style="elFont('client_name')">{{ receipt.client?.name }}</span>
                </div>

                <div v-if="!isHidden('amount')" class="field" :style="elPos('amount')">
                    <span class="label">المبلغ:</span>
                    <span class="value gold mono" :style="elFont('amount')">{{ fmt(receipt.amount_jod) }} JOD</span>
                </div>

                <div v-if="!isHidden('payment_method')" class="field" :style="elPos('payment_method')">
                    <span class="label">طريقة الدفع:</span>
                    <span class="value" :style="elFont('payment_method')">{{ paymentLabel(receipt.payment_method) }}</span>
                </div>

                <div v-if="!isHidden('commission') && receipt.bank_commission > 0" class="field" :style="elPos('commission')">
                    <span class="label">عمولة البنك:</span>
                    <span class="value mono" :style="elFont('commission')">{{ fmt(receipt.bank_commission) }} JOD</span>
                </div>

                <div v-if="!isHidden('signatures')" class="signatures" :style="sigStyle">
                    <div class="sig-box"><div class="sig-label">المحاسب</div><div class="sig-line"></div></div>
                    <div class="sig-box"><div class="sig-label">المدير المالي</div><div class="sig-line"></div></div>
                    <div class="sig-box"><div class="sig-label">العميل</div><div class="sig-line"></div></div>
                </div>

                <!-- الحقول المخصصة -->
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
import { ref, computed, onMounted } from 'vue';

const props = defineProps({ receipt: Object, templateUrl: String, layout: Object });

const statusLabels = { pending: 'معلق', approved: 'معتمد', rejected: 'مرفوض', editing: 'تحت التعديل' };
const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3 });
const formatDate = (d) => d ? new Date(d).toLocaleDateString('ar-SA', { year: 'numeric', month: 'long', day: 'numeric' }) : '';
const paymentLabel = (m) => ({ cash: 'نقداً', bank: 'تحويل بنكي', check: 'شيك' }[m] || m);

const defaults = {
    title: { x: 10, y: 30, fontSize: 16 },
    receipt_number: { x: 10, y: 45, fontSize: 11 },
    receipt_date: { x: 80, y: 45, fontSize: 10 },
    status: { x: 150, y: 45, fontSize: 10 },
    client_name: { x: 10, y: 58, fontSize: 12 },
    amount: { x: 10, y: 75, fontSize: 13 },
    payment_method: { x: 10, y: 88, fontSize: 10 },
    commission: { x: 80, y: 88, fontSize: 10 },
    signatures: { x: 10, y: 250, fontSize: 9, w: 190 },
};

const el = (id) => props.layout?.elements?.[id] || defaults[id] || { x: 10, y: 10, fontSize: 10 };
const isHidden = (id) => !!(props.layout?.elements?.[id]?.hidden);

const customFields = computed(() => {
    if (!props.layout?.elements) return {};
    const result = {};
    for (const [id, pos] of Object.entries(props.layout.elements)) {
        if (id.startsWith('custom_')) result[id] = pos;
    }
    return result;
});

const replaceVars = (text) => {
    const r = props.receipt;
    const map = {
        '{{اسم_العميل}}': r.client?.name || '',
        '{{كود_العميل}}': r.client?.code || '',
        '{{هاتف_العميل}}': r.client?.phone || '',
        '{{رقم_السند}}': r.receipt_number || '',
        '{{التاريخ}}': formatDate(r.receipt_date),
        '{{المبلغ}}': fmt(r.amount_jod) + ' JOD',
        '{{طريقة_الدفع}}': paymentLabel(r.payment_method),
        '{{الحالة}}': statusLabels[r.status] || r.status,
    };
    let result = text;
    for (const [key, val] of Object.entries(map)) result = result.replaceAll(key, val);
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

const sigStyle = computed(() => {
    const sp = el('signatures');
    return { position: 'absolute', right: sp.x + 'mm', top: sp.y + 'mm', width: (sp.w || 190) + 'mm' };
});

const pdfCanvas = ref(null);

const renderPdf = async () => {
    if (!props.templateUrl || !pdfCanvas.value) return;
    try {
        const pdfjsLib = await loadPdfJs();
        const pdf = await pdfjsLib.getDocument(props.templateUrl).promise;
        const page = await pdf.getPage(1);
        const scale = 2;
        const viewport = page.getViewport({ scale });
        const canvas = pdfCanvas.value;
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
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

.a4-page { width: 210mm; height: 297mm; position: relative; margin: 0 auto; overflow: hidden; background: white; font-family: 'Cairo', sans-serif; }
.pdf-bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; }
.fallback-bg { position: absolute; inset: 0; background: white; z-index: 0; }
.overlay { position: absolute; inset: 0; z-index: 1; direction: rtl; }

.field { font-family: 'Cairo', sans-serif; }
.label { color: #8b8680; font-size: 8pt; font-weight: 600; margin-left: 3px; }
.value { font-weight: 700; color: #1a1715; }
.value.gold { color: #96722a; }
.value.mono { font-family: 'JetBrains Mono', monospace; letter-spacing: 0.5px; }
.value.client-name { color: #1a1715; }
.value.status-approved { color: #0d7a3e; background: #e8f5ee; padding: 1px 8px; border-radius: 20px; font-size: 8pt; }
.value.status-pending { color: #8a6d0b; background: #fef9e7; padding: 1px 8px; border-radius: 20px; font-size: 8pt; }
.value.status-rejected { color: #b91c1c; background: #fef2f2; padding: 1px 8px; border-radius: 20px; font-size: 8pt; }

.signatures { display: flex; justify-content: space-between; }
.sig-box { text-align: center; width: 28%; }
.sig-label { font-size: 7.5pt; color: #8b8680; font-weight: 600; margin-bottom: 28px; font-family: 'Cairo', sans-serif; }
.sig-line { border-top: 1.5px solid #2c2417; padding-top: 4px; font-size: 6.5pt; color: #b0a89e; font-family: 'Cairo', sans-serif; }
.sig-line::after { content: 'التوقيع والختم'; }

.toolbar { display: flex; gap: 12px; justify-content: center; padding: 16px; background: linear-gradient(135deg, #f8f6f3, #ede9e3); border-bottom: 1px solid #e0dbd3; }
.print-btn { padding: 10px 28px; background: linear-gradient(135deg, #2c2417, #4a3c2e); color: #dbb84d; font-weight: 700; border: none; border-radius: 10px; cursor: pointer; font-size: 14px; font-family: 'Cairo', sans-serif; box-shadow: 0 2px 8px rgba(44,36,23,0.2); }
.back-btn { padding: 10px 24px; color: #5a5046; text-decoration: none; border: 1.5px solid #d4cec4; border-radius: 10px; font-size: 14px; font-family: 'Cairo', sans-serif; }

@media screen { body { background: #e8e4de; margin: 0; } .a4-page { box-shadow: 0 8px 30px rgba(0,0,0,.12); margin: 24px auto; border-radius: 4px; } }
@media print { .no-print { display: none !important; } body { margin: 0; padding: 0; } .a4-page { margin: 0; box-shadow: none; } @page { size: A4; margin: 0; } }
</style>
