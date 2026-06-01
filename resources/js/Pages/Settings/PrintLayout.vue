<template>
    <AppLayout>
        <template #header>محرر تخطيط الطباعة</template>
        <div class="space-y-4">
            <div v-if="$page.props.flash?.success" class="p-3 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>

            <!-- شريط التحكم -->
            <div class="bg-white rounded-2xl border p-4 shadow-sm flex flex-wrap items-center gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">نوع المطبوع</label>
                    <select v-model="docType" class="px-4 py-2 rounded-xl border text-sm font-bold" @change="loadLayout">
                        <optgroup label="مالي (Portrait)">
                            <option value="invoice">🧾 فاتورة</option>
                            <option value="transfer">💸 سند صرف</option>
                            <option value="receipt">📄 سند قبض</option>
                        </optgroup>
                        <optgroup label="محاسبي (Landscape)">
                            <option value="statement">📊 كشف حساب</option>
                            <option value="chart">🌳 شجرة الحسابات</option>
                            <option value="trial_balance">⚖️ ميزان المراجعة</option>
                            <option value="profit_loss">📈 أرباح وخسائر</option>
                            <option value="balance_sheet">🏦 ميزانية عمومية</option>
                        </optgroup>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">صفوف الجدول/صفحة</label>
                    <input v-model.number="rowsPerPage" type="number" min="1" max="50" class="w-20 px-3 py-2 rounded-xl border text-sm text-center font-mono"/>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 mb-1">بداية الجدول ص.تالية (mm)</label>
                    <input v-model.number="contTableY" type="number" min="5" max="100" class="w-20 px-3 py-2 rounded-xl border text-sm text-center font-mono"/>
                </div>
                <div class="flex-1"></div>
                <button @click="resetLayout" class="px-4 py-2 rounded-xl text-xs text-red-600 hover:bg-red-50 border border-red-200">🔄 إعادة تعيين</button>
                <button @click="saveLayout" :disabled="saving" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">💾 حفظ التخطيط</button>
            </div>

            <!-- منطقة المحرر -->
            <div class="flex gap-4">
                <!-- قائمة العناصر -->
                <div class="w-56 bg-white rounded-2xl border shadow-sm p-4 space-y-2 shrink-0 self-start sticky top-4">
                    <h4 class="text-sm font-bold text-gray-700 mb-2">📦 العناصر</h4>
                    <div v-for="el in currentElements" :key="el.id"
                        class="flex items-center gap-1.5 p-2 rounded-lg text-xs cursor-pointer transition-all"
                        :class="[
                            selectedEl === el.id ? 'bg-gold-100 border border-gold-400 font-bold' : 'bg-gray-50 hover:bg-gray-100 border border-transparent',
                            positions[el.id]?.hidden ? 'opacity-40' : ''
                        ]"
                        @click="selectedEl = el.id">
                        <button @click.stop="toggleHidden(el.id)" class="shrink-0 w-5 h-5 flex items-center justify-center rounded hover:bg-gray-200" :title="positions[el.id]?.hidden ? 'إظهار' : 'إخفاء'">
                            {{ positions[el.id]?.hidden ? '👁️‍🗨️' : '👁️' }}
                        </button>
                        <span class="flex-1" :class="positions[el.id]?.hidden ? 'line-through text-gray-400' : ''">{{ el.label }}</span>
                        <span class="text-[10px] font-mono text-gray-400" v-if="positions[el.id] && !positions[el.id]?.hidden">{{ Math.round(positions[el.id].x) }},{{ Math.round(positions[el.id].y) }}</span>
                        <span v-if="positions[el.id]?.hidden" class="text-[9px] text-red-400">مخفي</span>
                    </div>

                    <!-- زر إضافة حقل نص مخصص -->
                    <button @click="addCustomText" class="w-full mt-1 px-3 py-2 rounded-lg text-xs font-bold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-200 transition-all">
                        ✏️ + إضافة حقل نص
                    </button>

                    <div class="border-t pt-2 mt-2">
                        <p class="text-[10px] text-gray-400">اسحب العناصر على المعاينة</p>
                        <p class="text-[10px] text-gray-400">أو انقر + استخدم الأسهم</p>
                    </div>
                    <!-- تحكم دقيق -->
                    <div v-if="selectedEl && positions[selectedEl]" class="border-t pt-3 space-y-2">
                        <div class="flex items-center justify-between">
                            <h5 class="text-xs font-bold text-gray-600">📐 الموقع</h5>
                            <div class="flex items-center gap-1">
                                <button @click="toggleHidden(selectedEl)" class="text-[10px] px-2 py-0.5 rounded-full border" :class="positions[selectedEl]?.hidden ? 'bg-red-50 text-red-600 border-red-200' : 'bg-green-50 text-green-600 border-green-200'">
                                    {{ positions[selectedEl]?.hidden ? '🚫 مخفي' : '✅ ظاهر' }}
                                </button>
                                <button v-if="selectedEl.startsWith('custom_')" @click="removeCustomText(selectedEl)" class="text-[10px] px-2 py-0.5 rounded-full border bg-red-50 text-red-600 border-red-200 hover:bg-red-100">🗑️</button>
                            </div>
                        </div>

                        <!-- حقل النص المخصص -->
                        <div v-if="selectedEl.startsWith('custom_')" class="space-y-1.5">
                            <h5 class="text-xs font-bold text-indigo-600">✏️ محتوى النص</h5>
                            <textarea v-model="positions[selectedEl].text" rows="3" placeholder="اكتب النص هنا... استخدم {{اسم_العميل}} للمتغيرات" class="w-full px-2 py-1.5 rounded border text-xs resize-none" dir="rtl"></textarea>
                            <div class="space-y-1">
                                <p class="text-[9px] font-bold text-gray-500">📎 المتغيرات المتاحة:</p>
                                <div class="flex flex-wrap gap-1">
                                    <button v-for="v in availableVars" :key="v.key" @click="insertVar(v.key)" class="text-[9px] px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-200" :title="v.desc">
                                        {{ v.label }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="text-[10px] text-gray-500">X (mm)</label><input v-model.number="positions[selectedEl].x" type="number" step="0.5" class="w-full px-2 py-1 rounded border text-xs font-mono text-center" dir="ltr"/></div>
                            <div><label class="text-[10px] text-gray-500">Y (mm)</label><input v-model.number="positions[selectedEl].y" type="number" step="0.5" class="w-full px-2 py-1 rounded border text-xs font-mono text-center" dir="ltr"/></div>
                        </div>

                        <h5 class="text-xs font-bold text-gray-600 mt-2">📏 الحجم</h5>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[10px] text-gray-500">العرض (mm)</label>
                                <input v-model.number="positions[selectedEl].w" type="number" step="1" placeholder="تلقائي" class="w-full px-2 py-1 rounded border text-xs font-mono text-center" dir="ltr"/>
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-500">حجم الخط (pt)</label>
                                <input v-model.number="positions[selectedEl].fontSize" type="number" step="0.5" min="6" max="36" class="w-full px-2 py-1 rounded border text-xs font-mono text-center" dir="ltr"/>
                            </div>
                        </div>

                        <h5 class="text-xs font-bold text-gray-600 mt-2">🎨 الألوان</h5>
                        <div class="space-y-2">
                            <div>
                                <label class="text-[10px] text-gray-500">لون النص</label>
                                <div class="flex items-center gap-2">
                                    <input type="color" :value="positions[selectedEl].color || '#1a1715'" @input="positions[selectedEl].color = $event.target.value" class="w-7 h-7 rounded border cursor-pointer shrink-0"/>
                                    <input v-model="positions[selectedEl].color" placeholder="#1a1715" class="flex-1 px-2 py-1 rounded border text-[10px] font-mono" dir="ltr"/>
                                </div>
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-500">لون الخلفية</label>
                                <div class="flex items-center gap-2">
                                    <input type="color" :value="positions[selectedEl].bgColor || '#ffffff'" @input="positions[selectedEl].bgColor = $event.target.value" class="w-7 h-7 rounded border cursor-pointer shrink-0"/>
                                    <input v-model="positions[selectedEl].bgColor" placeholder="شفاف" class="flex-1 px-2 py-1 rounded border text-[10px] font-mono" dir="ltr"/>
                                </div>
                            </div>
                        </div>
                        <button @click="positions[selectedEl].color = ''; positions[selectedEl].bgColor = ''" class="text-[10px] text-gray-400 hover:text-red-500">🗑️ مسح الألوان</button>

                        <!-- ألوان الجدول (تظهر فقط للجدول) -->
                        <template v-if="selectedEl === 'items_table'">
                            <h5 class="text-xs font-bold text-purple-600 mt-2">🎨 ألوان الجدول</h5>
                            <div class="space-y-1.5">
                                <div class="flex items-center gap-2">
                                    <input type="color" :value="positions[selectedEl].thBg || '#2c2417'" @input="positions[selectedEl].thBg = $event.target.value" class="w-6 h-6 rounded cursor-pointer"/>
                                    <span class="text-[10px] text-gray-500 flex-1">خلفية الرأس</span>
                                    <input v-model="positions[selectedEl].thBg" placeholder="#2c2417" class="w-20 px-1 py-0.5 rounded border text-[10px] font-mono" dir="ltr"/>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="color" :value="positions[selectedEl].thColor || '#e8dcc8'" @input="positions[selectedEl].thColor = $event.target.value" class="w-6 h-6 rounded cursor-pointer"/>
                                    <span class="text-[10px] text-gray-500 flex-1">نص الرأس</span>
                                    <input v-model="positions[selectedEl].thColor" placeholder="#e8dcc8" class="w-20 px-1 py-0.5 rounded border text-[10px] font-mono" dir="ltr"/>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="color" :value="positions[selectedEl].thBorder || '#b8960b'" @input="positions[selectedEl].thBorder = $event.target.value" class="w-6 h-6 rounded cursor-pointer"/>
                                    <span class="text-[10px] text-gray-500 flex-1">خط الرأس</span>
                                    <input v-model="positions[selectedEl].thBorder" placeholder="#b8960b" class="w-20 px-1 py-0.5 rounded border text-[10px] font-mono" dir="ltr"/>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="color" :value="positions[selectedEl].tdEven || '#faf8f5'" @input="positions[selectedEl].tdEven = $event.target.value" class="w-6 h-6 rounded cursor-pointer"/>
                                    <span class="text-[10px] text-gray-500 flex-1">صف زوجي</span>
                                    <input v-model="positions[selectedEl].tdEven" placeholder="#faf8f5" class="w-20 px-1 py-0.5 rounded border text-[10px] font-mono" dir="ltr"/>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- منطقة المعاينة A4 -->
                <div class="flex-1 flex justify-center">
                    <div class="relative bg-white shadow-xl border" ref="editorArea"
                        :style="{ width: pageW + 'px', height: pageH + 'px' }"
                        @mousedown="onBgClick">

                        <!-- خلفية PDF -->
                        <canvas ref="pdfCanvas" class="absolute inset-0 w-full h-full pointer-events-none"></canvas>

                        <!-- شبكة مساعدة -->
                        <div class="absolute inset-0 pointer-events-none" style="background: repeating-linear-gradient(0deg, transparent, transparent 9px, rgba(0,0,0,0.03) 10px), repeating-linear-gradient(90deg, transparent, transparent 9px, rgba(0,0,0,0.03) 10px);"></div>

                        <!-- العناصر القابلة للسحب -->
                        <div v-for="el in currentElements" :key="el.id"
                            class="absolute cursor-move select-none transition-shadow"
                            :class="selectedEl === el.id ? 'ring-2 ring-gold-500 shadow-lg z-20' : 'hover:ring-1 hover:ring-blue-300 z-10'"
                            :style="getElStyle(el)"
                            @mousedown.stop="startDrag($event, el.id)">
                            <div class="px-2 py-1 rounded text-xs"
                                :style="{ fontSize: (positions[el.id]?.fontSize || el.defaultFontSize || 10) + 'pt', maxWidth: positions[el.id]?.w ? mmToPx(positions[el.id].w) + 'px' : '200px', overflow: 'hidden' }"
                                :class="[
                                    selectedEl === el.id ? 'bg-gold-100/90 border border-gold-400' : el.isCustom ? 'bg-indigo-50/90 border border-dashed border-indigo-400' : 'bg-white/80 border border-dashed border-gray-400'
                                ]">
                                <span class="opacity-60 mr-1">{{ el.icon }}</span>
                                {{ el.isCustom ? (positions[el.id]?.text || 'نص مخصص...') : el.preview }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ title: String, templateUrl: String, accountingTemplateUrl: String, layouts: Object });

const accountingTypes = ['statement', 'chart', 'trial_balance', 'profit_loss', 'balance_sheet'];
const isAccounting = computed(() => accountingTypes.includes(docType.value));
const activeTemplateUrl = computed(() => isAccounting.value ? props.accountingTemplateUrl : props.templateUrl);

const docType = ref('invoice');
const rowsPerPage = ref(10);
const contTableY = ref(20);
const selectedEl = ref(null);
const saving = ref(false);
const editorArea = ref(null);
const pdfCanvas = ref(null);

// أبعاد A4 بالبكسل (عرض ثابت 595px ≈ 210mm)
const SCALE = 595 / 210; // px per mm
const pageW = computed(() => isAccounting.value ? 842 : 595);
const pageH = computed(() => isAccounting.value ? 595 : 842);

// تعريف العناصر لكل نوع مطبوع
const elementsByType = {
    invoice: [
        { id: 'title', label: 'عنوان الفاتورة', icon: '📌', preview: 'فاتورة مبيعات', defaultFontSize: 16 },
        { id: 'invoice_number', label: 'رقم الفاتورة', icon: '#️⃣', preview: 'INV-20260523-0001', defaultFontSize: 11 },
        { id: 'invoice_date', label: 'التاريخ', icon: '📅', preview: '2026/05/23', defaultFontSize: 10 },
        { id: 'status', label: 'الحالة', icon: '🏷️', preview: 'معتمدة', defaultFontSize: 10 },
        { id: 'client_name', label: 'اسم العميل', icon: '👤', preview: 'اسم العميل', defaultFontSize: 12 },
        { id: 'items_table', label: 'جدول البنود', icon: '📊', preview: '# | الخدمة | الوصف | الكمية | السعر | الإجمالي', defaultFontSize: 9, hasWidth: true },
        { id: 'total', label: 'الإجمالي', icon: '💰', preview: 'الإجمالي: 000.000 JOD', defaultFontSize: 13, hasWidth: true },
        { id: 'signatures', label: 'التوقيعات', icon: '✍️', preview: 'المحاسب | المدير | العميل', defaultFontSize: 9, hasWidth: true },
    ],
    transfer: [
        { id: 'title', label: 'عنوان سند الصرف', icon: '📌', preview: 'سند صرف', defaultFontSize: 16 },
        { id: 'transfer_number', label: 'رقم السند', icon: '#️⃣', preview: 'TRF-20260523-0001', defaultFontSize: 11 },
        { id: 'transfer_date', label: 'التاريخ', icon: '📅', preview: '2026/05/23', defaultFontSize: 10 },
        { id: 'status', label: 'الحالة', icon: '🏷️', preview: 'معتمدة', defaultFontSize: 10 },
        { id: 'client_name', label: 'اسم العميل', icon: '👤', preview: 'اسم العميل', defaultFontSize: 12 },
        { id: 'agent_name', label: 'اسم الوكيل', icon: '🏢', preview: 'اسم الوكيل', defaultFontSize: 12 },
        { id: 'amount', label: 'المبلغ (SAR)', icon: '💵', preview: 'المبلغ: 0,000 SAR', defaultFontSize: 12 },
        { id: 'cost', label: 'التكلفة (JOD)', icon: '💴', preview: 'التكلفة: 000.000 JOD', defaultFontSize: 12 },
        { id: 'difference', label: 'الفرق', icon: '📊', preview: 'الفرق: 000.000 JOD', defaultFontSize: 11 },
        { id: 'signatures', label: 'التوقيعات', icon: '✍️', preview: 'المحاسب | المدير | العميل', defaultFontSize: 9, hasWidth: true },
    ],
    receipt: [
        { id: 'title', label: 'عنوان السند', icon: '📌', preview: 'سند قبض', defaultFontSize: 16 },
        { id: 'receipt_number', label: 'رقم السند', icon: '#️⃣', preview: 'REC-20260523-0001', defaultFontSize: 11 },
        { id: 'receipt_date', label: 'التاريخ', icon: '📅', preview: '2026/05/23', defaultFontSize: 10 },
        { id: 'status', label: 'الحالة', icon: '🏷️', preview: 'معتمد', defaultFontSize: 10 },
        { id: 'client_name', label: 'اسم العميل', icon: '👤', preview: 'اسم العميل', defaultFontSize: 12 },
        { id: 'amount', label: 'المبلغ (JOD)', icon: '💰', preview: 'المبلغ: 000.000 JOD', defaultFontSize: 13 },
        { id: 'payment_method', label: 'طريقة الدفع', icon: '💳', preview: 'نقدي / بنكي / شيك', defaultFontSize: 10 },
        { id: 'commission', label: 'العمولة', icon: '🏦', preview: 'عمولة البنك: 0.000', defaultFontSize: 10 },
        { id: 'signatures', label: 'التوقيعات', icon: '✍️', preview: 'المحاسب | المدير | العميل', defaultFontSize: 9, hasWidth: true },
    ],
    // === الأنواع المحاسبية (Landscape) ===
    statement: [
        { id: 'title', label: 'عنوان الكشف', icon: '📌', preview: 'كشف حساب', defaultFontSize: 14 },
        { id: 'entity_name', label: 'اسم الحساب/العميل/الوكيل', icon: '👤', preview: 'اسم الحساب', defaultFontSize: 12 },
        { id: 'entity_code', label: 'الكود', icon: '#️⃣', preview: 'ACC-001', defaultFontSize: 10 },
        { id: 'period', label: 'الفترة', icon: '📅', preview: '2026/01/01 → 2026/05/23', defaultFontSize: 10 },
        { id: 'currency', label: 'العملة', icon: '💱', preview: 'JOD', defaultFontSize: 10 },
        { id: 'summary_box', label: 'ملخص الأرصدة', icon: '📊', preview: 'افتتاحي | مدين | دائن | ختامي', defaultFontSize: 9, hasWidth: true },
        { id: 'data_table', label: 'جدول الحركات', icon: '📝', preview: '# | التاريخ | الوصف | مدين | دائن | الرصيد', defaultFontSize: 8, hasWidth: true },
        { id: 'signatures', label: 'التوقيعات', icon: '✍️', preview: 'المحاسب | المدير المالي', defaultFontSize: 9, hasWidth: true },
    ],
    chart: [
        { id: 'title', label: 'عنوان التقرير', icon: '📌', preview: 'دليل الحسابات', defaultFontSize: 14 },
        { id: 'report_date', label: 'تاريخ التقرير', icon: '📅', preview: '2026/05/23', defaultFontSize: 10 },
        { id: 'accounts_count', label: 'عدد الحسابات', icon: '📊', preview: 'عدد الحسابات: 45', defaultFontSize: 10 },
        { id: 'data_table', label: 'جدول الحسابات', icon: '🌳', preview: 'الكود | الاسم | النوع | العملة | الرصيد', defaultFontSize: 8, hasWidth: true },
        { id: 'signatures', label: 'التوقيعات', icon: '✍️', preview: 'المحاسب | المدقق | المدير', defaultFontSize: 9, hasWidth: true },
    ],
    trial_balance: [
        { id: 'title', label: 'عنوان التقرير', icon: '📌', preview: 'ميزان المراجعة', defaultFontSize: 14 },
        { id: 'period', label: 'الفترة', icon: '📅', preview: '2026/01/01 → 2026/05/23', defaultFontSize: 10 },
        { id: 'balance_status', label: 'حالة التوازن', icon: '⚖️', preview: '✅ الميزان متوازن', defaultFontSize: 10 },
        { id: 'data_table', label: 'جدول الميزان', icon: '📝', preview: 'الكود | الاسم | رصيد أول | حركات | رصيد آخر', defaultFontSize: 8, hasWidth: true },
        { id: 'signatures', label: 'التوقيعات', icon: '✍️', preview: 'المحاسب | المدير', defaultFontSize: 9, hasWidth: true },
    ],
    profit_loss: [
        { id: 'title', label: 'عنوان التقرير', icon: '📌', preview: 'قائمة الدخل', defaultFontSize: 14 },
        { id: 'period', label: 'الفترة', icon: '📅', preview: '2026/01/01 → 2026/05/23', defaultFontSize: 10 },
        { id: 'revenue_table', label: 'جدول الإيرادات', icon: '📈', preview: 'الكود | الإيراد | المبلغ', defaultFontSize: 9, hasWidth: true },
        { id: 'expense_table', label: 'جدول المصروفات', icon: '📉', preview: 'الكود | المصروف | المبلغ', defaultFontSize: 9, hasWidth: true },
        { id: 'net_income', label: 'صافي الدخل', icon: '💰', preview: 'صافي الربح: 000.000 JOD', defaultFontSize: 13 },
        { id: 'signatures', label: 'التوقيعات', icon: '✍️', preview: 'المحاسب | المدير', defaultFontSize: 9, hasWidth: true },
    ],
    balance_sheet: [
        { id: 'title', label: 'عنوان التقرير', icon: '📌', preview: 'الميزانية العمومية', defaultFontSize: 14 },
        { id: 'as_of_date', label: 'كما في تاريخ', icon: '📅', preview: 'كما في: 2026/05/23', defaultFontSize: 10 },
        { id: 'balance_status', label: 'حالة التوازن', icon: '⚖️', preview: '✅ متوازنة', defaultFontSize: 10 },
        { id: 'assets_table', label: 'جدول الأصول', icon: '📊', preview: 'الكود | الأصل | الرصيد', defaultFontSize: 9, hasWidth: true },
        { id: 'liabilities_table', label: 'جدول الالتزامات + ملكية', icon: '📋', preview: 'الكود | الالتزام | الرصيد', defaultFontSize: 9, hasWidth: true },
        { id: 'signatures', label: 'التوقيعات', icon: '✍️', preview: 'المحاسب | المدير', defaultFontSize: 9, hasWidth: true },
    ],
};

// المواقع الافتراضية (mm من أعلى يمين A4)
const defaultPositions = {
    invoice: {
        title: { x: 10, y: 30, fontSize: 16 },
        invoice_number: { x: 10, y: 45, fontSize: 11 },
        invoice_date: { x: 80, y: 45, fontSize: 10 },
        status: { x: 150, y: 45, fontSize: 10 },
        client_name: { x: 10, y: 58, fontSize: 12 },
        items_table: { x: 10, y: 72, fontSize: 9, w: 190 },
        total: { x: 10, y: 200, fontSize: 13, w: 190 },
        signatures: { x: 10, y: 250, fontSize: 9, w: 190 },
    },
    transfer: {
        title: { x: 10, y: 30, fontSize: 16 },
        transfer_number: { x: 10, y: 45, fontSize: 11 },
        transfer_date: { x: 80, y: 45, fontSize: 10 },
        status: { x: 150, y: 45, fontSize: 10 },
        client_name: { x: 10, y: 58, fontSize: 12 },
        agent_name: { x: 10, y: 68, fontSize: 12 },
        amount: { x: 10, y: 85, fontSize: 12 },
        cost: { x: 10, y: 95, fontSize: 12 },
        difference: { x: 10, y: 108, fontSize: 11 },
        signatures: { x: 10, y: 250, fontSize: 9, w: 190 },
    },
    receipt: {
        title: { x: 10, y: 30, fontSize: 16 },
        receipt_number: { x: 10, y: 45, fontSize: 11 },
        receipt_date: { x: 80, y: 45, fontSize: 10 },
        status: { x: 150, y: 45, fontSize: 10 },
        client_name: { x: 10, y: 58, fontSize: 12 },
        amount: { x: 10, y: 75, fontSize: 13 },
        payment_method: { x: 10, y: 88, fontSize: 10 },
        commission: { x: 80, y: 88, fontSize: 10 },
        signatures: { x: 10, y: 250, fontSize: 9, w: 190 },
    },
    // === محاسبي Landscape (297×210mm) ===
    statement: {
        title: { x: 10, y: 15, fontSize: 14 },
        entity_name: { x: 10, y: 28, fontSize: 12 },
        entity_code: { x: 120, y: 28, fontSize: 10 },
        period: { x: 200, y: 28, fontSize: 10 },
        currency: { x: 200, y: 15, fontSize: 10 },
        summary_box: { x: 10, y: 40, fontSize: 9, w: 277 },
        data_table: { x: 10, y: 58, fontSize: 8, w: 277 },
        signatures: { x: 10, y: 180, fontSize: 9, w: 277 },
    },
    chart: {
        title: { x: 10, y: 15, fontSize: 14 },
        report_date: { x: 200, y: 15, fontSize: 10 },
        accounts_count: { x: 120, y: 15, fontSize: 10 },
        data_table: { x: 10, y: 32, fontSize: 8, w: 277 },
        signatures: { x: 10, y: 180, fontSize: 9, w: 277 },
    },
    trial_balance: {
        title: { x: 10, y: 15, fontSize: 14 },
        period: { x: 120, y: 15, fontSize: 10 },
        balance_status: { x: 200, y: 15, fontSize: 10 },
        data_table: { x: 10, y: 32, fontSize: 8, w: 277 },
        signatures: { x: 10, y: 180, fontSize: 9, w: 277 },
    },
    profit_loss: {
        title: { x: 10, y: 15, fontSize: 14 },
        period: { x: 200, y: 15, fontSize: 10 },
        revenue_table: { x: 10, y: 32, fontSize: 9, w: 130 },
        expense_table: { x: 150, y: 32, fontSize: 9, w: 130 },
        net_income: { x: 10, y: 160, fontSize: 13 },
        signatures: { x: 10, y: 180, fontSize: 9, w: 277 },
    },
    balance_sheet: {
        title: { x: 10, y: 15, fontSize: 14 },
        as_of_date: { x: 200, y: 15, fontSize: 10 },
        balance_status: { x: 120, y: 15, fontSize: 10 },
        assets_table: { x: 10, y: 32, fontSize: 9, w: 130 },
        liabilities_table: { x: 150, y: 32, fontSize: 9, w: 130 },
        signatures: { x: 10, y: 180, fontSize: 9, w: 277 },
    },
};

const positions = reactive({});
const customTexts = ref([]);
let customCounter = 0;

// المتغيرات المتاحة حسب نوع المطبوع
const varsByType = {
    invoice: [
        { key: '{{اسم_العميل}}', label: 'اسم العميل', desc: 'اسم العميل المرتبط بالفاتورة' },
        { key: '{{كود_العميل}}', label: 'كود العميل', desc: 'كود العميل' },
        { key: '{{هاتف_العميل}}', label: 'هاتف العميل', desc: 'رقم الهاتف' },
        { key: '{{رقم_الفاتورة}}', label: 'رقم الفاتورة', desc: 'رقم الفاتورة' },
        { key: '{{التاريخ}}', label: 'التاريخ', desc: 'تاريخ الفاتورة' },
        { key: '{{الاجمالي}}', label: 'الإجمالي', desc: 'إجمالي الفاتورة JOD' },
        { key: '{{الحالة}}', label: 'الحالة', desc: 'حالة الفاتورة' },
    ],
    transfer: [
        { key: '{{اسم_العميل}}', label: 'اسم العميل', desc: 'اسم العميل' },
        { key: '{{كود_العميل}}', label: 'كود العميل', desc: 'كود العميل' },
        { key: '{{هاتف_العميل}}', label: 'هاتف العميل', desc: 'رقم هاتف العميل' },
        { key: '{{اسم_الوكيل}}', label: 'اسم الوكيل', desc: 'اسم الوكيل' },
        { key: '{{كود_الوكيل}}', label: 'كود الوكيل', desc: 'كود الوكيل' },
        { key: '{{هاتف_الوكيل}}', label: 'هاتف الوكيل', desc: 'رقم هاتف الوكيل' },
        { key: '{{رقم_السند}}', label: 'رقم السند', desc: 'رقم السند' },
        { key: '{{التاريخ}}', label: 'التاريخ', desc: 'تاريخ السند' },
        { key: '{{المبلغ}}', label: 'المبلغ SAR', desc: 'المبلغ بالريال' },
        { key: '{{التكلفة}}', label: 'التكلفة JOD', desc: 'التكلفة بالدينار' },
        { key: '{{الحالة}}', label: 'الحالة', desc: 'حالة السند' },
    ],
    receipt: [
        { key: '{{اسم_العميل}}', label: 'اسم العميل', desc: 'اسم العميل' },
        { key: '{{كود_العميل}}', label: 'كود العميل', desc: 'كود العميل' },
        { key: '{{هاتف_العميل}}', label: 'هاتف العميل', desc: 'رقم هاتف العميل' },
        { key: '{{رقم_السند}}', label: 'رقم السند', desc: 'رقم سند القبض' },
        { key: '{{التاريخ}}', label: 'التاريخ', desc: 'تاريخ السند' },
        { key: '{{المبلغ}}', label: 'المبلغ JOD', desc: 'المبلغ بالدينار' },
        { key: '{{طريقة_الدفع}}', label: 'طريقة الدفع', desc: 'نقد/بنك/شيك' },
        { key: '{{الحالة}}', label: 'الحالة', desc: 'حالة السند' },
    ],
    statement: [
        { key: '{{اسم_الحساب}}', label: 'اسم الحساب/العميل/الوكيل', desc: 'الاسم' },
        { key: '{{كود_الحساب}}', label: 'الكود', desc: 'كود الحساب' },
        { key: '{{الفترة}}', label: 'الفترة', desc: 'فترة الكشف' },
    ],
    chart: [
        { key: '{{التاريخ}}', label: 'تاريخ التقرير', desc: 'تاريخ الطباعة' },
    ],
    trial_balance: [
        { key: '{{الفترة}}', label: 'الفترة', desc: 'فترة التقرير' },
    ],
    profit_loss: [
        { key: '{{الفترة}}', label: 'الفترة', desc: 'فترة التقرير' },
    ],
    balance_sheet: [
        { key: '{{التاريخ}}', label: 'كما في تاريخ', desc: 'تاريخ الميزانية' },
    ],
};

const availableVars = computed(() => varsByType[docType.value] || []);

const currentElements = computed(() => {
    const base = elementsByType[docType.value] || [];
    const customs = customTexts.value
        .filter(c => c.docType === docType.value)
        .map(c => ({ id: c.id, label: c.label, icon: '✏️', preview: c.previewText || 'نص مخصص', defaultFontSize: 10, isCustom: true }));
    return [...base, ...customs];
});

const addCustomText = () => {
    customCounter++;
    const id = 'custom_' + Date.now() + '_' + customCounter;
    const label = 'نص مخصص ' + customCounter;
    customTexts.value.push({ id, label, docType: docType.value, previewText: 'نص مخصص' });
    positions[id] = { x: 30, y: 130, fontSize: 10, text: '', w: 80 };
    selectedEl.value = id;
};

const removeCustomText = (id) => {
    customTexts.value = customTexts.value.filter(c => c.id !== id);
    delete positions[id];
    selectedEl.value = null;
};

const insertVar = (varKey) => {
    if (!selectedEl.value || !positions[selectedEl.value]) return;
    const current = positions[selectedEl.value].text || '';
    positions[selectedEl.value].text = current + varKey;
};

// تحميل التخطيط المحفوظ أو الافتراضي
const loadLayout = () => {
    const saved = props.layouts?.[docType.value];
    const defaults = defaultPositions[docType.value];
    const src = saved?.elements || defaults;
    Object.keys(positions).forEach(k => delete positions[k]);
    // تحميل الحقول المخصصة من البيانات المحفوظة
    customTexts.value = customTexts.value.filter(c => c.docType !== docType.value);
    for (const [id, pos] of Object.entries(src)) {
        positions[id] = { ...pos };
        if (id.startsWith('custom_')) {
            customCounter++;
            customTexts.value.push({
                id,
                label: pos.customLabel || 'نص مخصص',
                docType: docType.value,
                previewText: (pos.text || 'نص مخصص').substring(0, 30),
            });
        }
    }
    rowsPerPage.value = saved?.rowsPerPage || 10;
    contTableY.value = saved?.contTableY || 20;
    selectedEl.value = null;
};

const resetLayout = () => {
    const defaults = defaultPositions[docType.value];
    Object.keys(positions).forEach(k => delete positions[k]);
    for (const [id, pos] of Object.entries(defaults)) {
        positions[id] = { ...pos };
    }
    rowsPerPage.value = 10;
    contTableY.value = 20;
};

const saveLayout = () => {
    saving.value = true;
    // حفظ label الحقول المخصصة داخل positions
    customTexts.value.forEach(c => {
        if (positions[c.id]) positions[c.id].customLabel = c.label;
    });
    router.post('/settings/print-layout', {
        type: docType.value,
        layout: { elements: { ...positions }, rowsPerPage: rowsPerPage.value, contTableY: contTableY.value },
    }, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; },
    });
};

// تحويل mm إلى بكسل على المعاينة
const mmToPx = (mm) => mm * SCALE;

const getElStyle = (el) => {
    const p = positions[el.id];
    if (!p) return { display: 'none' };
    if (p.hidden) return { display: 'none' };
    return {
        right: mmToPx(p.x) + 'px',
        top: mmToPx(p.y) + 'px',
        width: p.w ? mmToPx(p.w) + 'px' : 'auto',
    };
};

const toggleHidden = (id) => {
    if (!positions[id]) return;
    positions[id].hidden = !positions[id].hidden;
};

// سحب العناصر
let dragging = null;
let dragStart = { mx: 0, my: 0, ox: 0, oy: 0 };

const startDrag = (e, id) => {
    selectedEl.value = id;
    dragging = id;
    const p = positions[id];
    if (!p) return;
    dragStart = { mx: e.clientX, my: e.clientY, ox: p.x, oy: p.y };
    e.preventDefault();
};

const onMouseMove = (e) => {
    if (!dragging) return;
    const dx = -(e.clientX - dragStart.mx) / SCALE; // عكس لأن RTL
    const dy = (e.clientY - dragStart.my) / SCALE;
    const p = positions[dragging];
    if (!p) return;
    p.x = Math.max(0, Math.min(200, dragStart.ox + dx));
    p.y = Math.max(0, Math.min(290, dragStart.oy + dy));
};

const onMouseUp = () => { dragging = null; };

const onBgClick = (e) => {
    if (e.target === editorArea.value || e.target.tagName === 'CANVAS') {
        selectedEl.value = null;
    }
};

// أسهم لتحريك العنصر المحدد
const onKeyDown = (e) => {
    if (!selectedEl.value || !positions[selectedEl.value]) return;
    const step = e.shiftKey ? 5 : 0.5;
    const p = positions[selectedEl.value];
    const maxX = isAccounting.value ? 290 : 200;
    const maxY = isAccounting.value ? 200 : 290;
    switch(e.key) {
        case 'ArrowRight': p.x = Math.max(0, p.x - step); e.preventDefault(); break;
        case 'ArrowLeft': p.x = Math.min(maxX, p.x + step); e.preventDefault(); break;
        case 'ArrowUp': p.y = Math.max(0, p.y - step); e.preventDefault(); break;
        case 'ArrowDown': p.y = Math.min(maxY, p.y + step); e.preventDefault(); break;
    }
};

// تحميل PDF كخلفية
const renderPdf = async () => {
    const url = activeTemplateUrl.value;
    if (!url || !pdfCanvas.value) return;
    try {
        const pdfjsLib = await loadPdfJs();
        const pdf = await pdfjsLib.getDocument(url).promise;
        const page = await pdf.getPage(1);
        const viewport = page.getViewport({ scale: pageW.value / page.getViewport({ scale: 1 }).width });
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

onMounted(() => {
    loadLayout();
    renderPdf();
    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
    document.addEventListener('keydown', onKeyDown);
});

watch(docType, () => {
    setTimeout(renderPdf, 200);
});

onUnmounted(() => {
    document.removeEventListener('mousemove', onMouseMove);
    document.removeEventListener('mouseup', onMouseUp);
    document.removeEventListener('keydown', onKeyDown);
});
</script>
