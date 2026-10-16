<template>
  <!-- ملف واتساب للأعمال (E31): ما عند ميتا مقابل بيانات الشركة، والمزامنة بتأكيد. القيم المرسلة تُحسب في الخادم وحده -->
  <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6 space-y-3" data-test="business-profile">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">📇 ملف واتساب للأعمال</h3>
      <button type="button" data-test="profile-refresh" @click="load(true)" :disabled="loading"
              class="min-h-11 px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40">
        {{ loading ? '⏳ جارٍ القراءة…' : '🔄 قراءة من ميتا' }}
      </button>
    </div>

    <!-- اسم العرض للقراءة فقط: لا مسار في النظام يغيّره -->
    <p v-if="identity" class="text-xs text-gray-600 dark:text-gray-300" data-test="display-name">
      اسم العرض: <bdi dir="auto" class="font-bold">{{ identity.verified_name || '—' }}</bdi>
      — الحالة: {{ NAME_STATUS[identity.name_status] || identity.name_status || '—' }}.
      يُغيَّر من WhatsApp Manager فقط.
      <a href="https://business.facebook.com/wa/manage/phone-numbers/" target="_blank" rel="noopener noreferrer"
         class="font-bold text-gold-700 dark:text-gold-400 underline">فتح WhatsApp Manager ↗</a>
    </p>

    <p v-if="error" role="alert" class="text-sm text-red-700 dark:text-red-400" data-test="profile-error">❌ {{ error }}</p>

    <div v-if="rows.length">
      <div class="hidden sm:grid grid-cols-[minmax(7rem,9rem)_1fr_1fr_6.5rem] gap-3 pb-1 text-[11px] font-bold text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
        <span>الحقل</span><span>في ميتا الآن</span><span>في إعدادات الشركة</span><span>الحالة</span>
      </div>
      <ul class="divide-y divide-gray-100 dark:divide-gray-800">
        <li v-for="r in rows" :key="r.field" :data-test="`profile-row-${r.field}`"
            class="py-2 grid grid-cols-1 sm:grid-cols-[minmax(7rem,9rem)_1fr_1fr_6.5rem] gap-1 sm:gap-3 items-start text-sm">
          <label class="flex items-center gap-2 font-bold text-gray-800 dark:text-gray-100" :class="selectable(r) ? 'cursor-pointer' : ''">
            <input v-if="selectable(r)" v-model="picked" type="checkbox" :value="r.field" class="w-4 h-4 shrink-0" :aria-label="`مزامنة ${labels[r.field] || r.field}`"/>
            <span>{{ labels[r.field] || r.field }}</span>
          </label>
          <p class="min-w-0 text-gray-700 dark:text-gray-200">
            <span class="sm:hidden text-[11px] text-gray-500 dark:text-gray-400">في ميتا الآن: </span>
            <bdi dir="auto" class="break-words whitespace-pre-line [unicode-bidi:plaintext]">{{ show(r.field, r.current) }}</bdi>
          </p>
          <p class="min-w-0 text-gray-700 dark:text-gray-200">
            <span class="sm:hidden text-[11px] text-gray-500 dark:text-gray-400">في إعدادات الشركة: </span>
            <bdi dir="auto" class="break-words whitespace-pre-line [unicode-bidi:plaintext]">{{ show(r.field, r.desired) }}</bdi>
          </p>
          <span class="justify-self-start px-2 py-0.5 rounded-full text-[11px] font-bold" :class="(STATUS[r.status] || STATUS.different).cls">
            {{ (STATUS[r.status] || STATUS.different).label }}
          </span>
        </li>
      </ul>
    </div>

    <div v-if="rows.length" class="flex flex-wrap items-center gap-2">
      <button v-if="can.sync" type="button" data-test="profile-sync" :disabled="!picked.length || saving" @click="reviewOpen = true"
              class="min-h-11 px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
        📤 مزامنة المحدد ({{ picked.length }})
      </button>
      <span v-else class="text-xs text-gray-500 dark:text-gray-400">المزامنة مطفأة حالياً: المقارنة للعرض فقط.</span>
      <a href="/settings" class="text-xs font-bold text-gold-700 dark:text-gold-400 underline">تعديل بيانات الشركة</a>
    </div>
    <p class="text-[11px] text-gray-500 dark:text-gray-400">
      الروابط في النبذة (about) لا تكون قابلة للنقر. وما يُرسل لميتا يُحسب في الخادم من إعدادات الشركة وحدها، ونوع النشاط سفر (TRAVEL) دائماً.
    </p>
    <p v-if="notice" role="status" class="text-xs font-bold" :class="noticeOk ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400'" data-test="profile-notice">{{ notice }}</p>

    <!-- الصورة (اختيارية، خلف علمها) -->
    <div v-if="can.photo && loaded" class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 space-y-2" data-test="profile-photo">
      <p class="text-sm font-bold text-gray-700 dark:text-gray-200">الصورة</p>
      <div class="flex flex-wrap items-center gap-3">
        <img v-if="photoUrl && !photoBroken" :src="photoUrl" alt="صورة الملف الحالية" referrerpolicy="no-referrer" @error="photoBroken = true"
             class="w-16 h-16 rounded-full object-cover border border-gray-200 dark:border-gray-700"/>
        <span v-else class="text-xs text-gray-500 dark:text-gray-400">{{ current.profile_picture_url ? 'تعذّر عرض الصورة الحالية هنا.' : 'لا صورة.' }}</span>
        <label class="min-h-11 inline-flex items-center px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-xs font-bold text-gray-700 dark:text-gray-200 cursor-pointer"
               :class="photoBusy || !can.app_id ? 'opacity-50 pointer-events-none' : ''">
          رفع صورة…
          <input type="file" accept="image/jpeg,image/png" class="sr-only" :disabled="photoBusy || !can.app_id" @change="pickPhoto"/>
        </label>
        <button type="button" :disabled="photoBusy || !can.app_id" @click="useLogo"
                class="min-h-11 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-xs font-bold text-gray-700 dark:text-gray-200 disabled:opacity-50">استعمال شعار الشركة</button>
      </div>
      <p class="text-[11px] text-gray-500 dark:text-gray-400">JPEG أو PNG، مربعة تقريباً، 192×192 بكسل على الأقل، وحتى 5 ميغابايت. تظهر للعملاء فوراً.</p>
      <p v-if="!can.app_id" class="text-xs text-amber-700 dark:text-amber-400">أدخل App ID في «ربط قناة ميتا» أولاً.</p>
    </div>

    <AppModal :open="reviewOpen" title="مزامنة ملف واتساب للأعمال" size="lg" @close="reviewOpen = false">
      <p class="text-sm text-gray-700 dark:text-gray-200 mb-3">تُرسل هذه الحقول لميتا فيراها العملاء فوراً:</p>
      <dl class="space-y-3" data-test="profile-review">
        <div v-for="r in pickedRows" :key="r.field" class="rounded-lg border border-gray-200 dark:border-gray-700 p-2 text-sm">
          <dt class="font-bold text-gray-800 dark:text-gray-100">{{ labels[r.field] || r.field }}</dt>
          <dd class="mt-1 text-xs text-gray-500 dark:text-gray-400">قبل: <bdi dir="auto" class="break-words whitespace-pre-line [unicode-bidi:plaintext]">{{ show(r.field, r.current) }}</bdi></dd>
          <dd class="mt-0.5 text-xs text-green-800 dark:text-green-300">بعد: <bdi dir="auto" class="break-words whitespace-pre-line [unicode-bidi:plaintext]">{{ show(r.field, r.desired) }}</bdi></dd>
        </div>
      </dl>
      <template #footer>
        <button type="button" class="min-h-11 px-4 py-2 rounded-xl text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200" @click="reviewOpen = false">إلغاء</button>
        <button type="button" data-test="profile-confirm" :disabled="saving" @click="sync"
                class="min-h-11 px-5 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
          {{ saving ? '...' : '📤 مزامنة' }}
        </button>
      </template>
    </AppModal>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const STATUS = {
  same: { label: '✔ مطابق', cls: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' },
  different: { label: '≠ مختلف', cls: 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300' },
  too_long: { label: '⚠ طويل', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' },
  missing_local: { label: '⚠ ناقص', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' },
  invalid: { label: '⚠ غير صالح', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' },
};
const NAME_STATUS = {
  APPROVED: 'معتمد', AVAILABLE_WITHOUT_REVIEW: 'متاح بلا مراجعة', PENDING_REVIEW: 'قيد المراجعة',
  DECLINED: 'مرفوض', EXPIRED: 'منتهٍ', NONE: 'لا اسم',
};
const DEFAULT_LABELS = { about: 'النبذة (about)', description: 'الوصف', address: 'العنوان', email: 'البريد', websites: 'المواقع', vertical: 'نوع النشاط' };
const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };

const { confirm } = useConfirm();
const loading = ref(false);
const loaded = ref(false);
const saving = ref(false);
const photoBusy = ref(false);
const photoBroken = ref(false);
const error = ref('');
const notice = ref('');
const noticeOk = ref(true);
const diff = ref({});
const current = ref({});
const identity = ref(null);
const labels = ref(DEFAULT_LABELS);
const can = ref({ sync: false, photo: false, app_id: false });
const picked = ref([]);
const reviewOpen = ref(false);

const rows = computed(() => Object.entries(diff.value).map(([field, d]) => ({ field, ...d })));
const pickedRows = computed(() => rows.value.filter((r) => picked.value.includes(r.field)));
const selectable = (r) => can.value.sync && r.status === 'different';
const photoUrl = computed(() => (/^https:\/\//i.test(String(current.value.profile_picture_url || '')) ? current.value.profile_picture_url : ''));
const show = (field, v) => {
  if (Array.isArray(v)) return v.length ? v.join('\n') : '—';
  return v === null || v === undefined || v === '' ? '—' : String(v);
};

function apply(data) {
  if (data.diff) diff.value = data.diff;
  if (data.current) current.value = data.current;
  if (data.identity && Object.keys(data.identity).length) identity.value = data.identity;
  if (data.labels) labels.value = { ...DEFAULT_LABELS, ...data.labels };
  if (data.can) can.value = data.can;
  picked.value = picked.value.filter((f) => selectable({ status: diff.value[f]?.status }));
}

async function load(refresh = false) {
  if (loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const { data } = await axios.get('/api/whatsapp/business-profile', { ...api, params: refresh ? { refresh: 1 } : {} });
    apply(data);
    if (!data.ok) error.value = data.error || 'تعذّرت قراءة الملف من ميتا.';
    loaded.value = !!data.ok;
    photoBroken.value = false;
  } catch (e) {
    error.value = errorText(e, 'تعذّرت قراءة الملف من ميتا.');
  } finally {
    loading.value = false;
  }
}

async function sync() {
  if (saving.value || !picked.value.length) return;
  saving.value = true;
  notice.value = '';
  try {
    const { data } = await axios.post('/api/whatsapp/business-profile/sync', { fields: picked.value }, api);
    reviewOpen.value = false;
    picked.value = [];
    if (data.diff) diff.value = data.diff;
    noticeOk.value = !!data.verified;
    notice.value = data.verified ? '✅ حُدّث الملف وتحققنا منه عند ميتا.' : '⚠️ قبلت ميتا التحديث ولم تظهر القيم بعد: اقرأ الملف بعد دقيقة.';
  } catch (e) {
    reviewOpen.value = false;
    noticeOk.value = false;
    notice.value = `❌ ${errorText(e, 'تعذّرت المزامنة')}`;
  } finally {
    saving.value = false;
  }
}

async function sendPhoto(body, source) {
  if (!(await confirm({ title: 'صورة ملف الأعمال', message: 'تُستبدل صورة الملف عند واتساب فوراً ويراها كل العملاء.', confirmLabel: 'استبدال الصورة', danger: true }))) return;
  photoBusy.value = true;
  notice.value = '';
  try {
    await axios.post('/api/whatsapp/business-profile/photo', body, api);
    noticeOk.value = true;
    notice.value = source === 'logo' ? '✅ صارت صورة الملف شعار الشركة.' : '✅ رُفعت الصورة الجديدة.';
    await load(true);
  } catch (e) {
    noticeOk.value = false;
    notice.value = `❌ ${errorText(e, 'تعذّر رفع الصورة')}`;
  } finally {
    photoBusy.value = false;
  }
}

function pickPhoto(ev) {
  const file = ev.target.files?.[0];
  ev.target.value = '';
  if (!file) return;
  const body = new FormData();
  body.append('photo', file);
  sendPhoto(body, 'upload');
}

const useLogo = () => sendPhoto({ use_company_logo: true }, 'logo');

onMounted(() => load());
</script>
