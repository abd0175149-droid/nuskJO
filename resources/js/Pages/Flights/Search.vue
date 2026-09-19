<template>
  <AppLayout>
    <template #header>تجربة مزوّد أسعار الرحلات</template>

    <div class="space-y-6">

      <!-- حالة المزوّد -->
      <div class="rounded-xl border p-4 text-sm"
           :class="liveReady ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800'
                             : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800'">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span class="font-bold">{{ liveReady ? '🟢 الاتصال الحقيقي مُهيَّأ' : '🟡 وضع تجريبي' }}</span>
          <span class="text-gray-600 dark:text-gray-400">المزوّد الحالي:</span>
          <code class="px-2 py-0.5 rounded bg-white/70 dark:bg-gray-900/50 font-mono text-xs" dir="ltr">{{ driver }}</code>
          <span v-if="suppliers?.length" class="text-gray-600 dark:text-gray-400">
            مقصور على: <code class="font-mono text-xs" dir="ltr">{{ suppliers.join(', ') }}</code>
          </span>
        </div>
        <p v-if="!configured" class="mt-2 text-gray-600 dark:text-gray-400 leading-relaxed">
          الأسعار المعروضة الآن <b>مُصطنعة للمعاينة فقط ولا تُقدَّم لعميل</b>. لتشغيل الاتصال الحقيقي أضف في بيئة الخادم:
          <code class="font-mono text-xs block mt-1" dir="ltr">FLIGHTS_DRIVER=travelfusion · TF_LOGIN_ID=… · TF_ENDPOINT=https://xmltest.travelfusion.com · TF_SUPPLIERS=flynas</code>
        </p>
      </div>

      <!-- نموذج البحث -->
      <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5 space-y-4">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs text-gray-500">مسارات سريعة:</span>
          <button v-for="p in presets" :key="p.label" type="button" @click="applyPreset(p)"
                  class="px-3 py-1 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 hover:border-gold-400 hover:text-gold-700">
            {{ p.label }}
          </button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">من *</label>
            <input v-model="form.from" maxlength="3" dir="ltr" placeholder="AMM"
                   class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-mono uppercase text-center dark:text-white"/>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">إلى *</label>
            <input v-model="form.to" maxlength="3" dir="ltr" placeholder="JED"
                   class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-mono uppercase text-center dark:text-white"/>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">الذهاب *</label>
            <input v-model="form.depart_date" type="date" dir="ltr"
                   class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white"/>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">العودة</label>
            <input v-model="form.return_date" type="date" dir="ltr"
                   class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white"/>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">بالغون</label>
            <input v-model.number="form.adults" type="number" min="1" max="9" dir="ltr"
                   class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-center dark:text-white"/>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">أطفال</label>
            <input v-model.number="form.children" type="number" min="0" max="8" dir="ltr"
                   class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-center dark:text-white"/>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">رُضّع</label>
            <input v-model.number="form.infants" type="number" min="0" max="4" dir="ltr"
                   class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-center dark:text-white"/>
          </div>
        </div>

        <div class="flex flex-wrap items-end gap-3 pt-1">
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">المزوّد لهذا البحث</label>
            <select v-model="form.driver"
                    class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white">
              <option value="">افتراضي النظام ({{ driver }})</option>
              <option value="demo">تجريبي (بلا اتصال)</option>
              <option value="travelfusion" :disabled="!configured">
                Travelfusion (حقيقي){{ configured ? '' : ' — يتطلب LoginId 🔒' }}
              </option>
            </select>
            <p v-if="!configured" class="mt-1 text-[11px] text-gray-400">الخيار الحقيقي مقفل حتى تُضبط بيانات الحساب.</p>
          </div>
          <button @click="run" :disabled="loading || !canRun"
                  class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
            {{ loading ? '⏳ جاري البحث…' : '🔎 ابحث' }}
          </button>
          <span v-if="loading && form.driver === 'travelfusion'" class="text-xs text-gray-500">
            البحث الحقيقي قد يستغرق حتى 30 ثانية (استطلاع متكرر للنتائج).
          </span>
        </div>
      </div>

      <!-- خطأ -->
      <div v-if="error" class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4 text-sm text-red-700 dark:text-red-300">
        ❌ {{ error }}
      </div>

      <!-- المقارنة -->
      <div v-if="result" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5">
        <h3 class="font-bold text-sm mb-3 text-gray-800 dark:text-gray-100">⚖️ المقارنة مع الموقع الرسمي</h3>
        <div class="flex flex-wrap items-end gap-4">
          <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
              سعر الموقع الرسمي لنفس البحث (د.أ)
            </label>
            <input v-model.number="officialPrice" type="number" step="0.01" min="0" dir="ltr" placeholder="مثال: 145"
                   class="w-40 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-mono dark:text-white"/>
          </div>
          <div v-if="cheapest" class="text-sm">
            <span class="text-gray-500">أرخص عرض من المزوّد:</span>
            <b class="font-mono mr-1" dir="ltr">{{ fmt(cheapest.price) }} {{ cheapest.currency }}</b>
            <span class="text-gray-400 text-xs">({{ cheapest.carrier }})</span>
          </div>
          <div v-if="verdict" class="px-4 py-2 rounded-xl text-sm font-bold border" :class="verdict.cls">
            {{ verdict.text }}
          </div>
        </div>
        <p v-if="!officialPrice" class="mt-2 text-xs text-gray-400">
          أدخل السعر الذي تراه على موقع فلاي ناس لنفس المسار والتاريخ وعدد المسافرين — سيظهر الفرق لكل عرض.
        </p>
      </div>

      <!-- النتائج -->
      <div v-if="result" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 text-xs">
          <div class="flex items-center gap-3">
            <span class="font-bold text-gray-700 dark:text-gray-200">{{ offers.length }} عرضاً</span>
            <span class="text-gray-500">{{ result.query.from }} → {{ result.query.to }} · {{ result.query.depart_date }}<span v-if="result.query.return_date"> ⇄ {{ result.query.return_date }}</span></span>
            <span class="text-gray-500">{{ result.query.pax }} مسافر</span>
          </div>
          <div class="flex items-center gap-3">
            <span class="px-2 py-0.5 rounded-full font-bold"
                  :class="result.live ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
              {{ result.live ? 'بيانات حقيقية' : 'بيانات تجريبية' }}
            </span>
            <span class="font-mono text-gray-400" dir="ltr">{{ result.ms }} ms</span>
          </div>
        </div>

        <div v-if="result.error" class="px-5 py-2 text-xs bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-b border-amber-200 dark:border-amber-800">
          ⚠️ {{ result.error }}
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 dark:bg-gray-800/30 text-gray-500 dark:text-gray-400 text-xs">
              <th class="px-4 py-2.5 text-right font-bold">الناقل</th>
              <th class="px-4 py-2.5 text-right font-bold">الرحلة</th>
              <th class="px-4 py-2.5 text-right font-bold">الإقلاع</th>
              <th class="px-4 py-2.5 text-right font-bold">الوصول</th>
              <th class="px-4 py-2.5 text-center font-bold">المدة</th>
              <th class="px-4 py-2.5 text-center font-bold">توقفات</th>
              <th class="px-4 py-2.5 text-right font-bold">الأمتعة</th>
              <th class="px-4 py-2.5 text-center font-bold">السعر</th>
              <th v-if="officialPrice > 0" class="px-4 py-2.5 text-center font-bold">الفرق</th>
            </tr></thead>
            <tbody>
              <tr v-for="(o, i) in offers" :key="i"
                  class="border-t border-gray-100 dark:border-gray-700/50"
                  :class="i === 0 ? 'bg-green-50/50 dark:bg-green-900/10' : ''">
                <td class="px-4 py-2.5 text-right font-medium">
                  {{ o.carrier }}
                  <span v-if="i === 0" class="mr-1 text-[10px] px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-bold">الأرخص</span>
                </td>
                <td class="px-4 py-2.5 text-right font-mono text-xs text-gold-700" dir="ltr">{{ o.flight_no }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs" dir="ltr">{{ o.depart }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-xs" dir="ltr">{{ o.arrive }}</td>
                <td class="px-4 py-2.5 text-center font-mono text-xs">{{ o.duration ? dur(o.duration) : '—' }}</td>
                <td class="px-4 py-2.5 text-center text-xs">
                  <span :class="o.stops === 0 ? 'text-green-600 font-bold' : 'text-gray-500'">{{ o.stops === 0 ? 'مباشرة' : o.stops }}</span>
                </td>
                <td class="px-4 py-2.5 text-right text-xs text-gray-500">{{ o.baggage || '—' }}</td>
                <td class="px-4 py-2.5 text-center font-mono font-bold" dir="ltr">{{ fmt(o.price) }} <span class="text-[10px] text-gray-400">{{ o.currency }}</span></td>
                <td v-if="officialPrice > 0" class="px-4 py-2.5 text-center font-mono text-xs font-bold"
                    :class="o.price - officialPrice <= 0 ? 'text-green-600' : 'text-red-600'" dir="ltr">
                  {{ (o.price - officialPrice) > 0 ? '+' : '' }}{{ fmt(o.price - officialPrice) }}
                </td>
              </tr>
              <tr v-if="!offers.length">
                <td :colspan="officialPrice > 0 ? 9 : 8" class="px-5 py-10 text-center text-gray-400 text-sm">
                  لا نتائج — راجع الاستجابة الخام أدناه لمعرفة السبب.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- الاستجابة الخام -->
      <details v-if="result?.raw" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
        <summary class="cursor-pointer px-5 py-3 text-sm font-bold text-gray-700 dark:text-gray-200">
          🔍 الاستجابة الخام من المزوّد ({{ (result.raw.length / 1024).toFixed(1) }} KB)
        </summary>
        <div class="px-5 pb-4">
          <p class="text-xs text-gray-500 mb-2">
            محلّل النتائج مبدئي لأن توثيق شكل الاستجابة محجوب خلف حساب. أرسل لي هذا النص عند أول رد حقيقي لأعاير القراءة على أسماء الحقول الفعلية.
          </p>
          <pre class="text-[11px] font-mono bg-gray-900 text-gray-200 p-3 rounded-lg overflow-x-auto max-h-96" dir="ltr">{{ result.raw }}</pre>
        </div>
      </details>

    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  driver: String,
  configured: Boolean,
  endpoint: String,
  suppliers: { type: Array, default: () => [] },
});

const liveReady = computed(() => props.configured && props.driver === 'travelfusion');

const today = new Date();
const plus = (d) => new Date(today.getTime() + d * 86400000).toISOString().split('T')[0];

const form = ref({
  from: 'AMM', to: 'JED',
  depart_date: plus(14), return_date: '',
  adults: 1, children: 0, infants: 0,
  cabin: 'economy', driver: '',
});

const presets = [
  { label: 'عمّان ← جدة', from: 'AMM', to: 'JED' },
  { label: 'عمّان ← المدينة', from: 'AMM', to: 'MED' },
  { label: 'عمّان ← الرياض', from: 'AMM', to: 'RUH' },
  { label: 'عمّان ← الدمام', from: 'AMM', to: 'DMM' },
];
const applyPreset = (p) => { form.value.from = p.from; form.value.to = p.to; };

const loading = ref(false);
const error = ref('');
const result = ref(null);
const officialPrice = ref(null);

const offers = computed(() => result.value?.offers || []);
const cheapest = computed(() => offers.value[0] || null);

const canRun = computed(() =>
  form.value.from?.length === 3 && form.value.to?.length === 3 && !!form.value.depart_date
);

const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dur = (m) => `${Math.floor(m / 60)}س ${String(m % 60).padStart(2, '0')}د`;

const verdict = computed(() => {
  if (!officialPrice.value || !cheapest.value) return null;
  const diff = cheapest.value.price - officialPrice.value;
  const pct = (diff / officialPrice.value) * 100;
  if (diff <= 0) return { text: `✅ المزوّد أرخص بـ ${fmt(Math.abs(diff))} د.أ (${Math.abs(pct).toFixed(1)}%) — مناسب`,
                          cls: 'bg-green-50 text-green-700 border-green-200' };
  if (pct <= 5) return { text: `🟡 أعلى بـ ${fmt(diff)} د.أ (${pct.toFixed(1)}%) — مقبول حسب هامشك`,
                         cls: 'bg-amber-50 text-amber-700 border-amber-200' };
  return { text: `🔴 أعلى بـ ${fmt(diff)} د.أ (${pct.toFixed(1)}%) — غير مناسب للتسعير المباشر`,
           cls: 'bg-red-50 text-red-700 border-red-200' };
});

const run = async () => {
  loading.value = true; error.value = ''; result.value = null;
  try {
    const { data } = await axios.post('/api/flights/search', form.value, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    result.value = data;
    if (!data.ok && data.error) error.value = data.error;
  } catch (e) {
    error.value = e.response?.data?.message
      || (e.response?.data?.errors && Object.values(e.response.data.errors).flat().join(' · '))
      || 'تعذّر تنفيذ البحث';
  } finally {
    loading.value = false;
  }
};
</script>
