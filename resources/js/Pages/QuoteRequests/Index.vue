<template>
  <AppLayout>
    <template #header>طلبات التسعير والحجز</template>

    <div class="space-y-6">
      <div v-if="$page.props.flash?.success" class="p-4 rounded-xl border text-sm bg-green-50 border-green-200 text-green-700">✅ {{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error" class="p-4 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700">❌ {{ $page.props.flash.error }}</div>

      <!-- شرح موجز -->
      <div class="rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-4 text-sm text-gray-700 dark:text-gray-300">
        هذه الطلبات ينشئها بوت الواتساب تلقائياً عندما يطلب العميل سعراً نهائياً أو يؤكّد حجزاً — ويُحوّل المحادثة إليك.
        <b>أنت من يضع السعر</b>، والبوت لا يذكر أي مبلغ من عنده.
      </div>

      <!-- الفلاتر -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
          <button v-for="t in tabs" :key="t.key" type="button" @click="setStatus(t.key)"
                  class="px-3.5 py-1.5 rounded-xl text-xs font-bold border transition-colors"
                  :class="filters.status === t.key
                    ? 'bg-gold-50 dark:bg-gold-900/20 border-gold-400 text-gold-700 dark:text-gold-400'
                    : 'bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gold-400'">
            {{ t.label }}
            <span v-if="t.count" class="mr-1 px-1.5 py-0.5 rounded-full bg-red-500 text-white text-[10px]">{{ t.count }}</span>
          </button>
        </div>
        <input v-model="search" type="text" placeholder="بحث برقم الطلب أو الهاتف أو الاسم..."
               class="w-72 max-w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white focus:outline-none focus:ring-2 focus:ring-gold-500"
               @input="debounceSearch"/>
      </div>

      <!-- القائمة -->
      <div class="space-y-3">
        <div v-for="r in requests.data" :key="r.id"
             class="rounded-xl border overflow-hidden shadow-sm bg-white dark:bg-gray-900"
             :class="r.status === 'new' ? 'border-amber-300 dark:border-amber-700' : 'border-gray-200 dark:border-gray-700'">

          <!-- الرأس -->
          <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3 flex-wrap">
              <span class="font-mono text-xs font-bold text-gold-700" dir="ltr">{{ r.request_number }}</span>
              <span class="px-2 py-0.5 rounded text-xs font-bold" :class="typeClass(r.type)">{{ typeLabel(r.type) }}</span>
              <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</span>
              <span class="text-xs text-gray-400 font-mono" dir="ltr">{{ r.created_at }}</span>
            </div>
            <div class="flex items-center gap-2">
              <a v-if="r.conversation_id" :href="'/whatsapp/inbox?conv=' + r.conversation_id"
                 class="px-2.5 py-1 text-xs text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg font-bold">💬 المحادثة</a>
              <button v-if="canReply" @click="openPrice(r)"
                      class="px-3 py-1.5 rounded-lg text-xs font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-sm">
                {{ r.quoted_price_jod !== null ? '✏️ تعديل السعر' : '💰 تسعير' }}
              </button>
            </div>
          </div>

          <!-- التفاصيل -->
          <div class="px-5 py-3 grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-2 text-sm">
            <div><span class="text-gray-400 text-xs block">العميل</span>
              <span class="font-medium">{{ r.customer }}</span>
              <span v-if="r.client" class="text-[11px] text-green-600 block">✅ حساب مسجّل</span>
            </div>
            <div><span class="text-gray-400 text-xs block">الهاتف</span>
              <span class="font-mono text-xs" dir="ltr">{{ r.phone }}</span>
            </div>
            <div v-if="r.offer"><span class="text-gray-400 text-xs block">العرض</span><span>{{ r.offer }}</span></div>
            <div v-else-if="r.route"><span class="text-gray-400 text-xs block">المسار</span><span class="font-mono text-xs" dir="ltr">{{ r.route }}</span></div>
            <div><span class="text-gray-400 text-xs block">التاريخ</span>
              <span class="font-mono text-xs" dir="ltr">{{ r.depart_date || '—' }}<span v-if="r.return_date"> ⇄ {{ r.return_date }}</span></span>
            </div>
            <div><span class="text-gray-400 text-xs block">المسافرون</span>
              <span class="font-mono font-bold">{{ r.pax }}</span>
              <span class="text-[11px] text-gray-400 mr-1" dir="ltr">({{ r.pax_detail }})</span>
            </div>
            <div v-if="r.quoted_price_jod !== null" class="md:col-span-2">
              <span class="text-gray-400 text-xs block">السعر المُسعَّر</span>
              <span class="font-mono font-bold text-green-600" dir="ltr">{{ fmt(r.quoted_price_jod) }} د.أ</span>
              <span class="text-[11px] text-gray-400 mr-2">{{ r.quoted_by }} · {{ r.quoted_at }}</span>
            </div>
          </div>

          <div v-if="r.details" class="px-5 pb-3">
            <span class="text-gray-400 text-xs block mb-1">طلب العميل</span>
            <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ r.details }}</p>
          </div>
          <div v-if="r.quoted_note" class="px-5 pb-3">
            <span class="text-gray-400 text-xs block mb-1">ملاحظة التسعير</span>
            <p class="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap">{{ r.quoted_note }}</p>
          </div>

          <!-- إغلاق الطلب -->
          <div v-if="canReply && !['won','lost','cancelled'].includes(r.status)"
               class="px-5 py-2.5 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-2 text-xs">
            <span class="text-gray-400">إغلاق الطلب:</span>
            <button @click="setState(r,'won')" class="px-2.5 py-1 rounded-lg text-green-700 bg-green-50 dark:bg-green-900/20 font-bold hover:bg-green-100">✅ تم الحجز</button>
            <button @click="setState(r,'lost')" class="px-2.5 py-1 rounded-lg text-gray-600 bg-gray-100 dark:bg-gray-800 font-bold hover:bg-gray-200">لم يتم</button>
            <button @click="setState(r,'cancelled')" class="px-2.5 py-1 rounded-lg text-red-600 bg-red-50 dark:bg-red-900/20 font-bold hover:bg-red-100">ملغى</button>
          </div>
        </div>

        <div v-if="!requests.data?.length" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 py-16 text-center">
          <p class="text-5xl mb-3">📭</p>
          <p class="text-gray-500 dark:text-gray-400 font-bold">لا توجد طلبات في هذا التصنيف</p>
          <p class="text-xs text-gray-400 mt-1">ستظهر هنا تلقائياً عندما يطلب عميل سعراً عبر الواتساب</p>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="requests.last_page > 1" class="flex justify-center gap-1">
        <template v-for="link in requests.links" :key="link.label">
          <a v-if="link.url" :href="link.url" class="px-3 py-2 rounded-lg text-sm"
             :class="link.active ? 'bg-gold-500 text-black font-bold' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'" v-html="link.label"/>
          <span v-else class="px-3 py-2 text-sm text-gray-400" v-html="link.label"/>
        </template>
      </div>
    </div>

    <!-- مودال التسعير -->
    <div v-if="target" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="target=null">
      <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">💰 تسعير {{ target.request_number }}</h3>
          <button @click="target=null" class="text-gray-400 hover:text-red-500 text-xl">&times;</button>
        </div>

        <div class="rounded-xl bg-gray-50 dark:bg-gray-800/50 p-3 mb-4 text-sm space-y-1">
          <div><span class="text-gray-400 text-xs">العميل:</span> {{ target.customer }}</div>
          <div v-if="target.offer"><span class="text-gray-400 text-xs">العرض:</span> {{ target.offer }}</div>
          <div v-if="target.route"><span class="text-gray-400 text-xs">المسار:</span> <span class="font-mono text-xs" dir="ltr">{{ target.route }}</span></div>
          <div><span class="text-gray-400 text-xs">المسافرون:</span> {{ target.pax }} <span class="text-gray-400 text-xs" dir="ltr">({{ target.pax_detail }})</span></div>
        </div>

        <form @submit.prevent="submitPrice" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السعر الإجمالي (د.أ) *</label>
            <input v-model.number="pForm.quoted_price_jod" type="number" step="0.001" min="0" required dir="ltr"
                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-mono font-bold dark:text-white"/>
            <p v-if="pForm.quoted_price_jod > 0 && target.pax > 1" class="mt-1 text-xs text-gray-400" dir="ltr">
              ≈ {{ fmt(pForm.quoted_price_jod / target.pax) }} د.أ للفرد
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ملاحظة للعميل</label>
            <textarea v-model="pForm.quoted_note" rows="3" placeholder="مثال: السعر صالح 24 ساعة · يشمل وزن 20 كغ · الدفع بالمكتب"
                      class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm resize-none dark:text-white"></textarea>
          </div>

          <label class="flex items-start gap-2.5 p-3 rounded-xl border cursor-pointer"
                 :class="target.window_open ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 opacity-60'">
            <input type="checkbox" v-model="pForm.send_to_client" :disabled="!target.window_open" class="mt-0.5 rounded text-gold-500"/>
            <span class="text-sm">
              <b>إرسال السعر للعميل على واتساب الآن</b>
              <span v-if="target.window_open" class="block text-xs text-gray-500 mt-0.5">سيُرسل باسمك كرسالة موظف، ويوقف البوت مؤقتاً في هذه المحادثة.</span>
              <span v-else class="block text-xs text-red-600 mt-0.5">⚠️ انتهت نافذة 24 ساعة — لا يمكن الإرسال حتى يراسلك العميل من جديد. سيُحفظ السعر فقط.</span>
            </span>
          </label>

          <div class="flex gap-3">
            <button type="submit" :disabled="pForm.processing"
                    class="px-6 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
              {{ pForm.send_to_client ? '📤 حفظ وإرسال' : '💾 حفظ السعر' }}
            </button>
            <button type="button" @click="target=null" class="px-6 py-2.5 rounded-xl text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';

const props = defineProps({
  title: String,
  requests: Object,
  filters: Object,
  counts: Object,
  canReply: Boolean,
});

const search = ref(props.filters?.search || '');
let t = null;

const tabs = computed(() => [
  { key: 'open', label: 'المفتوحة', count: (props.counts?.new || 0) + (props.counts?.priced || 0) },
  { key: 'new', label: 'جديدة', count: props.counts?.new || 0 },
  { key: 'priced', label: 'مسعّرة', count: props.counts?.priced || 0 },
  { key: 'sent', label: 'أُرسلت', count: 0 },
  { key: 'won', label: 'تم الحجز', count: 0 },
  { key: 'lost', label: 'لم يتم', count: 0 },
]);

const fmt = (v) => Number(v || 0).toLocaleString('en', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const typeLabel = (t) => ({ flight: '✈️ تذاكر', package: '📦 باقة', visa: '🛂 تأشيرة', hotel: '🏨 فندق', transport: '🚌 نقل', other: 'أخرى' }[t] || t);
const typeClass = (t) => ({
  flight: 'bg-sky-100 text-sky-700', package: 'bg-purple-100 text-purple-700',
  visa: 'bg-teal-100 text-teal-700', hotel: 'bg-indigo-100 text-indigo-700',
  transport: 'bg-amber-100 text-amber-700',
}[t] || 'bg-gray-100 text-gray-600');

const statusLabel = (s) => ({ new: '🆕 جديد', priced: '💰 مسعّر', sent: '📤 أُرسل', won: '✅ تم', lost: 'لم يتم', cancelled: 'ملغى' }[s] || s);
const statusClass = (s) => ({
  new: 'bg-amber-100 text-amber-700', priced: 'bg-blue-100 text-blue-700',
  sent: 'bg-cyan-100 text-cyan-700', won: 'bg-green-100 text-green-700',
  lost: 'bg-gray-200 text-gray-600', cancelled: 'bg-red-100 text-red-700',
}[s] || 'bg-gray-100 text-gray-600');

const go = () => router.get('/quote-requests', {
  status: props.filters?.status || 'open',
  search: search.value || undefined,
}, { preserveState: true, replace: true });

const setStatus = (s) => router.get('/quote-requests', { status: s, search: search.value || undefined }, { preserveState: true, replace: true });
const debounceSearch = () => { clearTimeout(t); t = setTimeout(go, 400); };

const target = ref(null);
const pForm = useForm({ quoted_price_jod: null, quoted_note: '', send_to_client: true });

const openPrice = (r) => {
  target.value = r;
  pForm.clearErrors();
  pForm.quoted_price_jod = r.quoted_price_jod;
  pForm.quoted_note = r.quoted_note || '';
  pForm.send_to_client = !!r.window_open;
};

const submitPrice = () => {
  pForm.post(`/quote-requests/${target.value.id}/price`, {
    preserveScroll: true,
    onSuccess: () => { target.value = null; pForm.reset(); },
  });
};

const setState = (r, status) => {
  router.post(`/quote-requests/${r.id}/status`, { status }, { preserveScroll: true });
};
</script>
