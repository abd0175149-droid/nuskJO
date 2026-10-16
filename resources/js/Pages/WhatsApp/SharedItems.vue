<template>
  <AppLayout>
    <template #header>المواقع والبطاقات</template>

    <div class="space-y-6 pb-4">
      <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300">✅ {{ $page.props.flash.success }}</div>
      <div v-if="pageError" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300" role="alert">❌ {{ pageError }}</div>

      <div class="p-4 rounded-xl border text-sm leading-relaxed border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-amber-900 dark:text-amber-200" data-test="shared-items-warning">
        📍 الدبوس و👤 البطاقة يُرسلان من هذه القوائم وحدها، فلا يكتب أحد إحداثيات أو رقماً بيده. كل دبوس أو بطاقة رسالة خدمة مدفوعة.
        <span class="block mt-1 text-xs text-amber-800 dark:text-amber-300">لا رقم شخصي بلا موافقة مكتوبة من صاحبه. الحذف تعطيل، لأن رسائل قديمة تشير إليه.</span>
        <span v-if="!ready" class="block mt-2 font-bold">⚠️ لم تُشغَّل الهجرة بعد: لا مواقع ولا بطاقات.</span>
        <span v-else-if="!enabled" class="block mt-2 font-bold">⚠️ لا تظهر في الصندوق بعد: العلم «إرسال دبوس الموقع وبطاقات الاتصال» مطفأ في إعدادات الواتساب.</span>
      </div>

      <!-- ================= المواقع ================= -->
      <section class="space-y-3" aria-labelledby="places-title">
        <div class="flex justify-between items-center gap-3 flex-wrap">
          <h3 id="places-title" class="text-base font-bold text-gray-800 dark:text-gray-100">📍 المواقع <span class="text-xs font-normal text-gray-400">({{ places.length }})</span></h3>
          <button type="button" :disabled="!ready" @click="openPlace()" data-test="add-place" class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">+ موقع جديد</button>
        </div>
        <ul v-if="places.length" class="space-y-2">
          <li v-for="p in places" :key="p.id" data-test="place-row" class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-3 sm:p-4 space-y-1.5"
              :class="p.is_active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600 opacity-75'">
            <div class="flex flex-wrap items-center gap-2">
              <bdi dir="auto" class="font-bold text-gray-800 dark:text-gray-100 break-words">{{ p.name }}</bdi>
              <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ kinds[p.kind] || p.kind }}</span>
              <span v-if="p.is_default" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gold-100 text-gold-800 dark:bg-gold-900/30 dark:text-gold-200">⭐ افتراضي للبوت</span>
              <span v-if="p.bot_allowed" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">🤖 مسموح للبوت</span>
              <span v-if="!p.is_active" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">معطّل</span>
            </div>
            <p v-if="p.address" dir="auto" class="text-xs text-gray-500 dark:text-gray-400 break-words [unicode-bidi:plaintext]">{{ p.address }}</p>
            <div class="flex flex-wrap items-center gap-2 text-xs">
              <bdi dir="ltr" class="font-mono text-gray-600 dark:text-gray-300">{{ p.latitude }}, {{ p.longitude }}</bdi>
              <a :href="p.maps_url" target="_blank" rel="noopener noreferrer nofollow" class="font-bold text-gold-700 dark:text-gold-400 underline">🗺️ تحقق على الخرائط</a>
              <span class="flex items-center gap-1 ms-auto">
                <button type="button" @click="openPlace(p)" class="px-2 py-1 rounded-lg font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ تعديل</button>
                <button v-if="p.is_active" type="button" @click="deactivatePlace(p)" class="px-2 py-1 rounded-lg font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">⏸️ تعطيل</button>
              </span>
            </div>
          </li>
        </ul>
        <p v-else class="rounded-xl border-2 border-dashed p-6 text-center text-sm text-gray-500 dark:text-gray-400 border-gray-300 dark:border-gray-600">لا مواقع بعد. ابدأ بالمكتب، ثم نقاط تجمع الأفواج والفنادق المعتمدة.</p>
      </section>

      <!-- ================= البطاقات ================= -->
      <section class="space-y-3" aria-labelledby="cards-title">
        <div class="flex justify-between items-center gap-3 flex-wrap">
          <h3 id="cards-title" class="text-base font-bold text-gray-800 dark:text-gray-100">👤 بطاقات الاتصال <span class="text-xs font-normal text-gray-400">({{ cards.length }})</span></h3>
          <button type="button" :disabled="!ready" @click="openCard()" data-test="add-card" class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">+ بطاقة جديدة</button>
        </div>
        <ul v-if="cards.length" class="space-y-2">
          <li v-for="c in cards" :key="c.id" data-test="card-row" class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm p-3 sm:p-4 space-y-1.5"
              :class="c.is_active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 dark:border-gray-600 opacity-75'">
            <div class="flex flex-wrap items-center gap-2">
              <bdi dir="auto" class="font-bold text-gray-800 dark:text-gray-100 break-words">{{ c.label }}</bdi>
              <span v-if="c.bot_allowed" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">🤖 مسموح للبوت</span>
              <span v-if="c.wa_id" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">على واتساب</span>
              <span v-if="!c.is_active" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">معطّل</span>
            </div>
            <p dir="auto" class="text-xs text-gray-600 dark:text-gray-300 break-words [unicode-bidi:plaintext]">{{ c.formatted_name }}<template v-if="c.org"> — {{ c.org }}</template><template v-if="c.job_title"> ({{ c.job_title }})</template></p>
            <div class="flex flex-wrap items-center gap-2 text-xs">
              <bdi dir="ltr" class="font-mono text-gray-600 dark:text-gray-300" data-test="masked-phone">{{ c.phone_masked }}</bdi>
              <span class="text-gray-500 dark:text-gray-400">✍️ موافقة {{ c.consented_at }}</span>
              <span class="flex items-center gap-1 ms-auto">
                <button type="button" @click="openCard(c)" class="px-2 py-1 rounded-lg font-bold text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">✏️ تعديل</button>
                <button v-if="c.is_active" type="button" @click="deactivateCard(c)" class="px-2 py-1 rounded-lg font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20">⏸️ تعطيل</button>
              </span>
            </div>
          </li>
        </ul>
        <p v-else class="rounded-xl border-2 border-dashed p-6 text-center text-sm text-gray-500 dark:text-gray-400 border-gray-300 dark:border-gray-600">لا بطاقات بعد. المقترح: المكتب، والمندوب في السعودية، والمرشد، والمحاسبة، كلٌّ بموافقة صاحب الرقم.</p>
      </section>
    </div>

    <!-- ================= نموذج الموقع ================= -->
    <AppModal :open="placeOpen" :title="placeForm.id ? 'تعديل موقع' : 'موقع جديد'" :dirty="placeDirty" @close="placeOpen = false">
      <form @submit.prevent="savePlace" class="space-y-3 text-sm" data-test="place-form">
        <label class="block">
          <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">الاسم (يراه العميل) *</span>
          <input v-model="placeForm.name" data-autofocus maxlength="60" required dir="auto" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 [unicode-bidi:plaintext]"/>
          <span v-if="placeForm.errors.name" class="block mt-1 text-xs text-red-500" role="alert">{{ placeForm.errors.name }}</span>
        </label>
        <label class="block">
          <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">العنوان</span>
          <input v-model="placeForm.address" maxlength="160" dir="auto" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 [unicode-bidi:plaintext]"/>
        </label>
        <div v-if="attendancePlaces.length" class="rounded-lg p-2.5 bg-gray-50 dark:bg-gray-800/60">
          <label class="block">
            <span class="block mb-1 text-xs font-bold text-gray-600 dark:text-gray-300">انسخ من مواقع البصمة (تعبئة فقط، لا حفظ)</span>
            <select @change="copyAttendance" data-test="copy-attendance" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100">
              <option value="">اختر موقعاً…</option>
              <option v-for="a in attendancePlaces" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </label>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="block">
            <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">خط العرض *</span>
            <input v-model="placeForm.latitude" inputmode="decimal" required dir="ltr" placeholder="31.9539" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 font-mono"/>
            <span v-if="placeForm.errors.latitude" class="block mt-1 text-xs text-red-500" role="alert">{{ placeForm.errors.latitude }}</span>
          </label>
          <label class="block">
            <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">خط الطول *</span>
            <input v-model="placeForm.longitude" inputmode="decimal" required dir="ltr" placeholder="35.9106" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 font-mono"/>
            <span v-if="placeForm.errors.longitude" class="block mt-1 text-xs text-red-500" role="alert">{{ placeForm.errors.longitude }}</span>
          </label>
        </div>
        <a v-if="placeMapsUrl" :href="placeMapsUrl" target="_blank" rel="noopener noreferrer nofollow" class="inline-block text-xs font-bold text-gold-700 dark:text-gold-400 underline">🗺️ تحقق على الخرائط</a>
        <label class="block">
          <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">النوع</span>
          <select v-model="placeForm.kind" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100">
            <option v-for="(label, key) in kinds" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
        <label v-if="placeForm.kind === 'office'" class="flex items-center gap-2 min-h-8"><input v-model="placeForm.is_default" type="checkbox" class="w-4 h-4"/> ⭐ المكتب الافتراضي للبوت (واحد فقط)</label>
        <label class="flex items-center gap-2 min-h-8"><input v-model="placeForm.bot_allowed" type="checkbox" class="w-4 h-4"/> 🤖 مسموح للبوت</label>
        <label class="flex items-center gap-2 min-h-8"><input v-model="placeForm.is_active" type="checkbox" class="w-4 h-4"/> فعّال</label>
        <div class="flex gap-3 pt-1">
          <button type="submit" :disabled="placeForm.processing" class="px-6 py-2.5 rounded-xl font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">حفظ</button>
          <button type="button" @click="placeOpen = false" class="px-6 py-2.5 rounded-xl text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">إلغاء</button>
        </div>
      </form>
    </AppModal>

    <!-- ================= نموذج البطاقة ================= -->
    <AppModal :open="cardOpen" :title="cardForm.id ? 'تعديل بطاقة' : 'بطاقة جديدة'" :dirty="cardDirty" @close="cardOpen = false">
      <form @submit.prevent="saveCard" class="space-y-3 text-sm" data-test="card-form">
        <label class="block">
          <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">تسمية داخلية *</span>
          <input v-model="cardForm.label" data-autofocus maxlength="60" required dir="auto" placeholder="المندوب في جدة" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 [unicode-bidi:plaintext]"/>
          <span v-if="cardForm.errors.label" class="block mt-1 text-xs text-red-500" role="alert">{{ cardForm.errors.label }}</span>
        </label>
        <label class="block">
          <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">الاسم على البطاقة *</span>
          <input v-model="cardForm.formatted_name" maxlength="80" required dir="auto" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 [unicode-bidi:plaintext]"/>
          <span v-if="cardForm.errors.formatted_name" class="block mt-1 text-xs text-red-500" role="alert">{{ cardForm.errors.formatted_name }}</span>
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="block">
            <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">الجهة</span>
            <input v-model="cardForm.org" maxlength="80" dir="auto" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 [unicode-bidi:plaintext]"/>
          </label>
          <label class="block">
            <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">المسمى</span>
            <input v-model="cardForm.job_title" maxlength="60" dir="auto" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 [unicode-bidi:plaintext]"/>
          </label>
        </div>
        <label class="block">
          <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">الرقم بالصيغة الدولية *</span>
          <input v-model="cardForm.phone_e164" required dir="ltr" inputmode="tel" placeholder="+9627XXXXXXXX" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 font-mono"/>
          <span v-if="cardForm.errors.phone_e164" class="block mt-1 text-xs text-red-500" role="alert">{{ cardForm.errors.phone_e164 }}</span>
        </label>
        <label class="block">
          <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">رقم واتساب (أرقام فقط، إن كان الرقم على واتساب)</span>
          <input v-model="cardForm.wa_id" dir="ltr" inputmode="numeric" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 font-mono"/>
          <span class="block mt-1 text-xs text-gray-400">يظهر للعميل زرّا «مراسلة» و«حفظ جهة الاتصال».</span>
          <span v-if="cardForm.errors.wa_id" class="block mt-1 text-xs text-red-500" role="alert">{{ cardForm.errors.wa_id }}</span>
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="block">
            <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">البريد</span>
            <input v-model="cardForm.email" type="email" maxlength="120" dir="ltr" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100"/>
            <span v-if="cardForm.errors.email" class="block mt-1 text-xs text-red-500" role="alert">{{ cardForm.errors.email }}</span>
          </label>
          <label class="block">
            <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">رابط (https)</span>
            <input v-model="cardForm.url" maxlength="200" dir="ltr" placeholder="https://" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100"/>
            <span v-if="cardForm.errors.url" class="block mt-1 text-xs text-red-500" role="alert">{{ cardForm.errors.url }}</span>
          </label>
        </div>
        <fieldset class="rounded-xl p-3 border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 space-y-2">
          <legend class="px-1 text-xs font-bold text-amber-900 dark:text-amber-200">✍️ موافقة صاحب الرقم</legend>
          <label class="block">
            <span class="block mb-1 text-xs text-gray-700 dark:text-gray-300">صاحب الرقم إن كان موظفاً</span>
            <select v-model="cardForm.consent_user_id" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100">
              <option :value="null">—</option>
              <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </label>
          <label class="block">
            <span class="block mb-1 text-xs text-gray-700 dark:text-gray-300">من وافق ومتى وكيف *</span>
            <input v-model="cardForm.consent_note" maxlength="200" required dir="auto" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 [unicode-bidi:plaintext]"/>
            <span v-if="cardForm.errors.consent_note" class="block mt-1 text-xs text-red-500" role="alert">{{ cardForm.errors.consent_note }}</span>
          </label>
          <label class="block">
            <span class="block mb-1 text-xs text-gray-700 dark:text-gray-300">تاريخ الموافقة *</span>
            <input v-model="cardForm.consented_at" type="date" required dir="ltr" class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100"/>
            <span v-if="cardForm.errors.consented_at" class="block mt-1 text-xs text-red-500" role="alert">{{ cardForm.errors.consented_at }}</span>
          </label>
          <label class="flex items-start gap-2 text-xs text-amber-900 dark:text-amber-200" data-test="consent-confirm">
            <input v-model="cardForm.consent_confirmed" type="checkbox" class="mt-0.5 w-4 h-4 shrink-0"/>
            <span>وافق صاحب الرقم كتابياً على إرسال رقمه للعملاء.</span>
          </label>
          <span v-if="cardForm.errors.consent_confirmed" class="block text-xs text-red-500" role="alert">{{ cardForm.errors.consent_confirmed }}</span>
        </fieldset>
        <label class="flex items-center gap-2 min-h-8"><input v-model="cardForm.bot_allowed" type="checkbox" class="w-4 h-4"/> 🤖 مسموح للبوت (المقترح: لا في البداية)</label>
        <label class="flex items-center gap-2 min-h-8"><input v-model="cardForm.is_active" type="checkbox" class="w-4 h-4"/> فعّالة</label>
        <div class="flex gap-3 pt-1">
          <button type="submit" :disabled="cardForm.processing || !cardForm.consent_confirmed" class="px-6 py-2.5 rounded-xl font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">حفظ</button>
          <button type="button" @click="cardOpen = false" class="px-6 py-2.5 rounded-xl text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">إلغاء</button>
        </div>
      </form>
    </AppModal>
  </AppLayout>
</template>

<script setup>
// المواقع وبطاقات الاتصال المعتمدة (E33): للمدير وحده. لا خريطة مضمّنة ولا سكربت خارجي (CSP من E26): رابط «تحقق على
// الخرائط» يفتح الإحداثيات في تبويب جديد. ومواقع البصمة تعبّئ النموذج فقط ولا تُحفظ تلقائياً.
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import AppModal from '@/Components/AppModal.vue';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
  ready: Boolean,
  enabled: Boolean,
  kinds: { type: Object, default: () => ({}) },
  places: { type: Array, default: () => [] },
  cards: { type: Array, default: () => [] },
  attendancePlaces: { type: Array, default: () => [] },
  staff: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const pageError = ref('');
const errorsOf = (errors) => Object.values(errors || {})[0] || 'تعذّر الحفظ';

// ===== المواقع =====
const placeOpen = ref(false);
const placeForm = useForm({ id: null, name: '', address: '', latitude: '', longitude: '', kind: 'office', is_default: false, bot_allowed: false, is_active: true });
const placeBase = ref('');
const placeDirty = computed(() => placeOpen.value && JSON.stringify(placeForm.data()) !== placeBase.value);
const placeMapsUrl = computed(() => {
  const lat = Number(placeForm.latitude);
  const lng = Number(placeForm.longitude);
  if (placeForm.latitude === '' || placeForm.longitude === '' || !Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return '';
  return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
});

function openPlace(p = null) {
  placeForm.clearErrors();
  Object.assign(placeForm, p
    ? { id: p.id, name: p.name, address: p.address || '', latitude: p.latitude, longitude: p.longitude, kind: p.kind, is_default: p.is_default, bot_allowed: p.bot_allowed, is_active: p.is_active }
    : { id: null, name: '', address: '', latitude: '', longitude: '', kind: 'office', is_default: !props.places.some((x) => x.is_default), bot_allowed: false, is_active: true });
  placeBase.value = JSON.stringify(placeForm.data());
  placeOpen.value = true;
}

function copyAttendance(e) {
  const a = props.attendancePlaces.find((x) => String(x.id) === e.target.value);
  if (!a) return;
  placeForm.latitude = a.latitude;
  placeForm.longitude = a.longitude;
  if (!placeForm.name) placeForm.name = a.name;
  e.target.value = '';
}

function savePlace() {
  const opts = { preserveScroll: true, onSuccess: () => { placeBase.value = JSON.stringify(placeForm.data()); placeOpen.value = false; } };
  if (placeForm.id) placeForm.put(`/whatsapp/shared-items/places/${placeForm.id}`, opts);
  else placeForm.post('/whatsapp/shared-items/places', opts);
}

async function deactivatePlace(p) {
  pageError.value = '';
  if (!(await confirm({ title: 'تعطيل موقع', message: `تعطيل «${p.name}»؟ لا يُرسل بعدها، ويبقى في الرسائل القديمة.`, confirmLabel: '⏸️ تعطيل', danger: true }))) return;
  router.delete(`/whatsapp/shared-items/places/${p.id}`, { preserveScroll: true, onError: (e) => { pageError.value = errorsOf(e); } });
}

// ===== البطاقات =====
const cardOpen = ref(false);
const today = () => new Date().toISOString().slice(0, 10);
const cardForm = useForm({ id: null, label: '', formatted_name: '', org: '', job_title: '', phone_e164: '', wa_id: '', email: '', url: '',
  consent_user_id: null, consent_note: '', consented_at: '', consent_confirmed: false, bot_allowed: false, is_active: true });
const cardBase = ref('');
const cardDirty = computed(() => cardOpen.value && JSON.stringify(cardForm.data()) !== cardBase.value);

function openCard(c = null) {
  cardForm.clearErrors();
  Object.assign(cardForm, c
    ? { id: c.id, label: c.label, formatted_name: c.formatted_name, org: c.org || '', job_title: c.job_title || '', phone_e164: c.phone_e164, wa_id: c.wa_id || '',
      email: c.email || '', url: c.url || '', consent_user_id: c.consent_user_id, consent_note: c.consent_note || '', consented_at: c.consented_at || '',
      consent_confirmed: false, bot_allowed: c.bot_allowed, is_active: c.is_active }
    : { id: null, label: '', formatted_name: '', org: '', job_title: '', phone_e164: '', wa_id: '', email: '', url: '', consent_user_id: null,
      consent_note: '', consented_at: today(), consent_confirmed: false, bot_allowed: false, is_active: true });
  cardBase.value = JSON.stringify(cardForm.data());
  cardOpen.value = true;
}

function saveCard() {
  const opts = { preserveScroll: true, onSuccess: () => { cardBase.value = JSON.stringify(cardForm.data()); cardOpen.value = false; } };
  cardForm.transform((d) => ({ ...d, org: d.org || null, job_title: d.job_title || null, wa_id: d.wa_id || null, email: d.email || null, url: d.url || null }));
  if (cardForm.id) cardForm.put(`/whatsapp/shared-items/cards/${cardForm.id}`, opts);
  else cardForm.post('/whatsapp/shared-items/cards', opts);
}

async function deactivateCard(c) {
  pageError.value = '';
  if (!(await confirm({ title: 'تعطيل بطاقة', message: `تعطيل «${c.label}»؟ لا تُرسل بعدها، وتبقى في الرسائل القديمة.`, confirmLabel: '⏸️ تعطيل', danger: true }))) return;
  router.delete(`/whatsapp/shared-items/cards/${c.id}`, { preserveScroll: true, onError: (e) => { pageError.value = errorsOf(e); } });
}
</script>
