<template>
  <!-- تبويب «📤 المشاركة» (E38): بطاقة الحالة 9:16 للنشر اليدوي. لا نشر آلي (رموز QR الرسمية كانت من ميتا وأُزيلت) -->
  <div class="space-y-5" data-test="share-tab">
    <p v-if="loading && !share" class="text-sm text-gray-500 dark:text-gray-400">...جارٍ التحميل</p>
    <p v-if="error" role="alert" class="p-3 rounded-xl border text-sm bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">❌ {{ error }}</p>
    <p v-if="notice" role="status" class="p-3 rounded-xl border text-sm bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200">{{ notice }}</p>

    <template v-if="share">
      <div v-if="!share.for_bot" data-test="not-for-bot"
           class="p-3 rounded-xl border text-sm bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-200">
        ⚠️ لا يقرأ البوت هذا العرض الآن، فلا بطاقة حالة: {{ share.blockers.join('، ') || 'راجع «ما يراه البوت»' }}.
      </div>

      <section v-if="share.story" class="rounded-xl border p-4 space-y-3 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900" data-test="story">
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
          انشرها يدوياً من تطبيق واتساب على هاتف الرقم. لا نشر آلي.
          التنزيل يعيد التوليد إن كانت البطاقة أقدم من آخر تعديل.
        </p>
      </section>
    </template>

  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({ offerId: { type: Number, required: true } });

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const share = ref(null);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');

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

async function generate() {
  if (await run(() => axios.post(`/offers/${props.offerId}/story`, {}, api), 'تعذّر توليد بطاقة الحالة')) load();
}

onMounted(load);
</script>
