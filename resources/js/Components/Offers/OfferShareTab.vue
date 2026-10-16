<template>
  <!-- تبويب «📤 المشاركة» (E38): رموز QR والروابط القصيرة الرسمية، وبطاقة الحالة 9:16 للنشر اليدوي. لا نشر آلي -->
  <div class="space-y-5" data-test="share-tab">
    <p v-if="loading && !share" class="text-sm text-gray-500 dark:text-gray-400">...جارٍ التحميل</p>
    <p v-if="error" role="alert" class="p-3 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">❌ {{ error }}</p>
    <p v-if="notice" role="status" class="p-3 rounded-xl border text-sm bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200">{{ notice }}</p>

    <template v-if="share">
      <div v-if="!share.for_bot" data-test="not-for-bot"
           class="p-3 rounded-xl border text-sm bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200">
        ⚠️ لا يقرأ البوت هذا العرض الآن، فلا رموز جديدة ولا بطاقة حالة: {{ share.blockers.join('، ') || 'راجع «ما يراه البوت»' }}.
      </div>

      <section v-if="share.flags.qr" class="rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">🔗 روابط ورموز QR</h4>
          <button v-if="share.can.update" type="button" data-test="qr-new" :disabled="!share.for_bot || busy" @click="createOpen = true"
                  class="min-h-11 px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 disabled:opacity-50">➕ رمز جديد</button>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
          المسح يفتح محادثة مع رقمنا برسالة معبأة فيها عنوان العرض ومرجعه، والعميل هو من يرسل.
          <template v-if="share.limit"> المستعمل <bdi dir="ltr" class="tabular-nums">{{ share.limit.count }}/{{ share.limit.max }}</bdi> لرقمنا.</template>
        </p>
        <p class="text-[11px] text-amber-700 dark:text-amber-400">ميتا لا توفر إحصاءات للرموز. الأرقام هنا من رسائل احتفظت بالمرجع فقط.</p>
        <div v-if="share.links.length" class="grid grid-cols-1 xl:grid-cols-2 gap-3">
          <QrLinkCard v-for="l in share.links" :key="l.id" :link="l" :can-manage="share.can.update" card-toggle :busy="busy"
                      @remove="remove" @toggle-card="toggleCard"/>
        </div>
        <p v-else class="rounded-xl border-2 border-dashed p-6 text-center text-sm text-gray-500 border-gray-300 dark:border-gray-600">لا رموز لهذا العرض بعد.</p>
      </section>

      <section v-if="share.flags.story && share.story" class="rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900" data-test="story">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">🖼️ بطاقة الحالة 9:16</h4>
          <span v-if="share.story.url && share.story.is_stale" class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">قديمة</span>
        </div>
        <img v-if="share.story.url" :src="share.story.url" alt="بطاقة الحالة" class="w-40 max-w-full rounded-lg border border-gray-200 dark:border-gray-700"/>
        <div v-else class="w-40 aspect-[9/16] grid place-items-center rounded-lg border-2 border-dashed text-xs text-gray-400 border-gray-300 dark:border-gray-600">لم تُولَّد بعد</div>
        <div class="flex flex-wrap gap-2">
          <button v-if="share.can.update" type="button" data-test="story-generate" :disabled="!share.for_bot || busy" @click="generate"
                  class="min-h-11 px-4 py-2 rounded-xl text-sm font-bold text-gold-800 dark:text-gold-200 bg-gold-100 dark:bg-gold-900/30 disabled:opacity-50">
            {{ busy ? '...جارٍ التوليد' : '🔄 توليد' }}
          </button>
          <a v-if="share.for_bot" :href="`/offers/${offerId}/story/download`" data-test="story-download"
             class="min-h-11 inline-flex items-center px-4 py-2 rounded-xl text-sm font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800">⬇️ تنزيل للنشر</a>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
          انشرها يدوياً من تطبيق واتساب أعمال للوكالة. إن كان رقمنا الرسمي على Cloud API وحده فلا يمكن النشر منه. لا نشر آلي.
          التنزيل يعيد التوليد إن كانت البطاقة أقدم من آخر تعديل.
        </p>
      </section>
    </template>

    <QrCreateDialog :open="createOpen" :endpoint="`/offers/${offerId}/qr`" :channels="share?.channels || {}"
                    @close="createOpen = false" @created="onCreated" @failed="load"/>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import QrCreateDialog from '@/Components/WhatsApp/QrCreateDialog.vue';
import QrLinkCard from '@/Components/WhatsApp/QrLinkCard.vue';
import { useConfirm } from '@/composables/useConfirm';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({ offerId: { type: Number, required: true } });

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const { confirm } = useConfirm();
const share = ref(null);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const createOpen = ref(false);

async function load() {
  loading.value = true;
  try {
    const { data } = await axios.get(`/api/offers/${props.offerId}/share`, api);
    share.value = data;
    error.value = '';
  } catch (e) {
    error.value = errorText(e, 'تعذّر تحميل المشاركة');
  } finally {
    loading.value = false;
  }
}

async function run(fn, fallback) {
  busy.value = true;
  error.value = '';
  try {
    return await fn();
  } catch (e) {
    error.value = errorText(e, fallback);
    return null;
  } finally {
    busy.value = false;
  }
}

function onCreated(data) {
  createOpen.value = false;
  notice.value = data?.warning || '';
  load();
}

async function remove(link) {
  if (!(await confirm({
    title: 'حذف الرمز',
    message: 'الرموز المطبوعة ستتوقف عن فتح المحادثة برسالة معبأة، والرابط القصير يتوقف كذلك. لا رجعة في الحذف.',
    confirmLabel: '🗑️ حذف الرمز',
    danger: true,
  }))) return;
  if (await run(() => axios.delete(`/offers/${props.offerId}/qr/${link.id}`, api), 'تعذّر حذف الرمز')) load();
}

async function toggleCard(link) {
  const res = await run(() => axios.post(`/offers/${props.offerId}/card-qr`, { link_id: link.on_card ? null : link.id }, api), 'تعذّر تعديل البطاقة');
  if (res) {
    notice.value = 'تغيّرت بطاقة A4: أعد توليدها من «ما يراه البوت» (أو يولّدها البوت قبل إرسالها).';
    load();
  }
}

async function generate() {
  if (await run(() => axios.post(`/offers/${props.offerId}/story`, {}, api), 'تعذّر توليد بطاقة الحالة')) load();
}

onMounted(load);
</script>
