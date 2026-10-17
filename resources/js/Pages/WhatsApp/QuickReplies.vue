<template>
  <AppLayout>
    <template #header>الردود الجاهزة</template>

    <div class="space-y-6 pb-4">
      <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>

      <div class="p-4 rounded-xl border text-sm leading-relaxed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 text-gray-600 dark:text-gray-300">
        نصوص يدرجها الموظف في المحادثة بنقرة (⚡ أو «/» في خانة فارغة)، فتُملأ المتغيرات من المحادثة نفسها ثم يراجع النص ويرسله بنفسه داخل نافذة الـ24 ساعة.
        <b>لا أسعار ثابتة</b> (استعمل <bdi dir="ltr" class="font-mono">{{ token('quoted_price') }}</bdi>)، ولا طلب لصور الجوازات أو الهويات عبر واتساب، وأي نص يذكر بيانات الدفع ينتظر اعتماد المدير.
        <p v-if="!enabled" class="mt-2 font-bold text-amber-700 dark:text-amber-400">⚠️ الردود لا تظهر للموظفين بعد: العلم «الردود الجاهزة» مطفأ في إعدادات الواتساب.</p>
      </div>

      <div class="flex justify-between items-center gap-3 flex-wrap">
        <h3 class="text-base font-bold text-gray-800 dark:text-gray-100">الردود <span class="text-xs font-normal text-gray-400">({{ replies.length }})</span></h3>
        <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">+ رد جديد</button>
      </div>

      <div v-if="replies.length" class="space-y-3">
        <div v-for="(r, i) in replies" :key="r.id" class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-4 space-y-2"
             :class="r.is_active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600'">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div class="min-w-0 flex items-center gap-2 flex-wrap">
              <h4 class="font-bold text-gray-800 dark:text-gray-100">{{ r.title }}</h4>
              <span class="px-2 py-0.5 rounded-full text-[11px] bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ categories[r.category] || r.category }}</span>
              <span v-if="r.needs_approval" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">بانتظار اعتماد المدير</span>
              <span v-else-if="!r.is_active" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">موقوف</span>
              <span v-if="r.approved_by" class="text-[11px] text-gray-400">اعتمده {{ r.approved_by }}</span>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button type="button" @click="move(i, -1)" :disabled="i === 0" :aria-label="'تحريك ' + r.title + ' للأعلى'" class="min-w-9 min-h-9 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-30">▲</button>
              <button type="button" @click="move(i, 1)" :disabled="i === replies.length - 1" :aria-label="'تحريك ' + r.title + ' للأسفل'" class="min-w-9 min-h-9 rounded-lg text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-30">▼</button>
              <button v-if="r.needs_approval && canApprove" type="button" @click="approve(r)" class="px-2 py-1 rounded-lg text-xs font-bold text-green-700 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20">✅ اعتماد</button>
              <button type="button" @click="openForm(r)" class="px-2 py-1 rounded-lg text-xs font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ تعديل</button>
              <button type="button" @click="remove(r)" :aria-label="'حذف ' + r.title" title="حذف" class="min-w-9 min-h-9 rounded-lg text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">🗑️</button>
            </div>
          </div>
          <p dir="auto" class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line leading-relaxed [unicode-bidi:plaintext]">{{ r.body }}</p>
          <p class="text-[11px] text-gray-400"><span v-if="r.updated_by">{{ r.updated_by }} · </span><span v-if="r.updated_at" dir="ltr" class="font-mono">{{ r.updated_at }}</span></p>
        </div>
      </div>
      <div v-else class="rounded-xl border-2 border-dashed p-10 text-center space-y-3 border-gray-300 dark:border-gray-600">
        <p class="text-sm text-gray-500 dark:text-gray-400">لا ردود جاهزة بعد.</p>
        <button type="button" @click="openForm()" class="px-4 py-2 rounded-xl text-sm font-bold text-gold-800 bg-gold-100 dark:bg-gold-900/30 dark:text-gold-200">+ أضف أول رد</button>
      </div>
    </div>

    <!-- النموذج في AppModal: Escape والخلفية لا يضيّعان تعديلات غير محفوظة -->
    <AppModal :open="showForm" :title="editing ? 'تعديل رد جاهز' : 'رد جاهز جديد'" size="lg" :dirty="dirty" @close="close">
      <form @submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="sm:col-span-2">
            <label for="qr-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">العنوان *</label>
            <input id="qr-title" data-autofocus v-model="form.title" maxlength="80" required placeholder="مثال: ترحيب وطلب التفاصيل"
                   class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
            <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
          </div>
          <div>
            <label for="qr-category" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">التصنيف</label>
            <select id="qr-category" v-model="form.category" class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm">
              <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
        </div>
        <div>
          <label for="qr-body" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">النص *</label>
          <div class="flex flex-wrap gap-1 mb-1.5" role="group" aria-label="إدراج متغير">
            <button v-for="(label, key) in variables" :key="key" type="button" @click="insertVar(key)" :title="label"
                    class="px-2 py-0.5 rounded-lg text-[11px] font-mono border border-gray-200 dark:border-gray-700 hover:border-gold-400" dir="ltr">{{ token(key) }}</button>
          </div>
          <textarea id="qr-body" ref="bodyEl" v-model="form.body" rows="6" maxlength="1000" required dir="auto"
                    class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm leading-relaxed resize-y [unicode-bidi:plaintext]"></textarea>
          <p class="text-xs text-gray-400"><span class="font-mono" dir="ltr">{{ form.body.length }} / 1000</span></p>
          <p v-if="form.errors.body" class="mt-1 text-xs text-red-500" role="alert">{{ form.errors.body }}</p>
          <p v-if="paymentHint" class="mt-1 text-xs font-bold text-amber-700 dark:text-amber-400">💳 يذكر بيانات دفع: سيُحفظ موقوفاً حتى يعتمده المدير.</p>
        </div>
        <div>
          <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">معاينة بقيم مثال</p>
          <div dir="auto" class="p-3 rounded-xl bg-gold-50 dark:bg-gold-900/10 text-sm whitespace-pre-line [unicode-bidi:plaintext]">{{ preview || '—' }}</div>
        </div>
        <label for="qr-active" class="flex items-center gap-2 cursor-pointer">
          <input id="qr-active" v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded text-gold-500"/>
          <span class="text-sm text-gray-700 dark:text-gray-300">مفعّل (يظهر للموظفين)</span>
        </label>
        <div class="flex gap-3 pt-1">
          <button type="submit" :disabled="form.processing" class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">حفظ</button>
          <button type="button" @click="close" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">إلغاء</button>
        </div>
      </form>
    </AppModal>
  </AppLayout>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import AppModal from '@/Components/AppModal.vue';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
  replies: { type: Array, default: () => [] },
  categories: { type: Object, default: () => ({}) },
  variables: { type: Object, default: () => ({}) },
  canApprove: Boolean,
  enabled: Boolean,
});

const { confirm } = useConfirm();
const showForm = ref(false);
const editing = ref(null);
const bodyEl = ref(null);
const form = useForm({ title: '', body: '', category: 'general', is_active: true });
const baseline = ref('');
const dirty = computed(() => showForm.value && JSON.stringify(form.data()) !== baseline.value);

const token = (key) => `{{${key}}}`;
const SAMPLE = computed(() => ({
  name: 'أحمد', client_code: 'C-1001', staff_name: usePage().props.auth?.user?.name || 'الموظف',
  company_name: 'نُسك', company_phone: '+962 6 000 0000', request_number: 'QR-20261016-0001',
  offer_title: 'عمرة المولد', quoted_price: '0.000 د.أ',
}));
const preview = computed(() => form.body.replace(/\{\{\s*([\p{L}\p{N}_]+)\s*\}\}/gu, (m, k) => SAMPLE.value[k] ?? m));
const paymentHint = computed(() => /(cliq|كليك|iban|آيبان|ايبان|رقم الحساب|حساب بنكي|تحويل إلى|حوالة)/iu.test(form.body));

function openForm(r = null) {
  editing.value = r;
  form.clearErrors();
  form.title = r?.title || '';
  form.body = r?.body || '';
  form.category = r?.category || 'general';
  form.is_active = r ? r.is_active || r.needs_approval : true;
  baseline.value = JSON.stringify(form.data());
  showForm.value = true;
}
const close = () => { showForm.value = false; };

async function insertVar(key) {
  const el = bodyEl.value;
  const at = el ? el.selectionStart : form.body.length;
  form.body = form.body.slice(0, at) + token(key) + form.body.slice(el ? el.selectionEnd : at);
  await nextTick();
  el?.focus();
  el?.setSelectionRange(at + token(key).length, at + token(key).length);
}

function save() {
  const opts = { preserveScroll: true, onSuccess: () => { baseline.value = JSON.stringify(form.data()); showForm.value = false; } };
  if (editing.value) form.put('/whatsapp/quick-replies/' + editing.value.id, opts);
  else form.post('/whatsapp/quick-replies', opts);
}

async function remove(r) {
  if (!(await confirm({ title: 'حذف رد جاهز', message: `حذف «${r.title}»؟ تبقى نسخته في سجل التدقيق.`, confirmLabel: '🗑️ حذف', danger: true }))) return;
  router.delete('/whatsapp/quick-replies/' + r.id, { preserveScroll: true });
}

async function approve(r) {
  if (!(await confirm({ title: 'اعتماد رد يذكر بيانات دفع', message: `راجع النص جيداً قبل الاعتماد:\n\n${r.body}`, confirmLabel: '✅ اعتماد وتفعيل', danger: true }))) return;
  router.post(`/whatsapp/quick-replies/${r.id}/approve`, {}, { preserveScroll: true });
}

function move(i, dir) {
  const ids = props.replies.map((r) => r.id);
  const j = i + dir;
  [ids[i], ids[j]] = [ids[j], ids[i]];
  router.post('/whatsapp/quick-replies/reorder', { ids }, { preserveScroll: true });
}

// حماية المسودة: مغادرة الصفحة والنموذج معدّل تسأل أولاً
const beforeUnload = (e) => { if (dirty.value) { e.preventDefault(); e.returnValue = ''; } };
let offBefore = () => {};
onMounted(() => {
  window.addEventListener('beforeunload', beforeUnload);
  offBefore = router.on('before', (event) => {
    const visit = event.detail.visit;
    const own = visit.url.pathname.startsWith('/whatsapp/quick-replies') && visit.method !== 'get';
    if (dirty.value && !own && !window.confirm('في النموذج تعديلات لم تُحفظ. مغادرة الصفحة؟')) return false;
  });
});
onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnload);
  offBefore();
});
</script>
