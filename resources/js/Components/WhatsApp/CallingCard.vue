<template>
  <!-- المكالمات (E39، العلم calls_webhook): آخر قراءة من ميتا وطريقة الرد ونص المتابعة. تفعيل الاتصال عند ميتا من الأمر وحده -->
  <section id="calling" data-test="calling-card" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6 space-y-4 scroll-mt-20">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">📞 المكالمات</h3>
      <button type="button" data-test="calling-refresh" @click="refresh" :disabled="reading"
              class="min-h-11 px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40">
        {{ reading ? '⏳ جارٍ القراءة…' : '🔄 قراءة من ميتا' }}
      </button>
    </div>

    <p v-if="state.warning" role="alert" data-test="calling-warning"
       class="rounded-xl border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/30 p-3 text-sm font-bold text-red-700 dark:text-red-300">
      ⛔ الاتصال مفعّل ولا توجد طريقة رد — كل مكالمة ستفوت، وتُسحب أذونات المعاودة بعد 4 مكالمات بلا رد.
    </p>

    <!-- آخر قراءة -->
    <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-sm">
      <p v-if="!snapshot" class="text-gray-500 dark:text-gray-400" data-test="calling-unread">لم تُقرأ الإعدادات من ميتا بعد.</p>
      <dl v-else class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
        <div class="flex items-center justify-between gap-3">
          <dt class="text-gray-500 dark:text-gray-400">الاتصال</dt>
          <dd class="font-bold" :class="enabled ? 'text-green-700 dark:text-green-400' : 'text-gray-700 dark:text-gray-200'">{{ enabled ? 'مفعّل' : 'غير مفعّل' }}
            <bdi dir="ltr" class="font-mono font-normal text-xs">{{ snapshot.calling.status || '—' }}</bdi></dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-gray-500 dark:text-gray-400">أيقونة الاتصال</dt>
          <dd><bdi dir="ltr" class="font-mono text-xs">{{ snapshot.calling.call_icon_visibility || '—' }}</bdi></dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-gray-500 dark:text-gray-400">إذن المعاودة</dt>
          <dd><bdi dir="ltr" class="font-mono text-xs">{{ snapshot.calling.callback_permission_status || '—' }}</bdi></dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-gray-500 dark:text-gray-400">ساعات الاتصال</dt>
          <dd><bdi dir="ltr" class="font-mono text-xs">{{ hours }}</bdi></dd>
        </div>
      </dl>
      <p v-if="snapshot" class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">
        {{ SOURCES[snapshot.source] || snapshot.source }} · <bdi dir="ltr">{{ when(snapshot.at) }}</bdi>
      </p>
    </div>

    <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-700 dark:text-gray-200" data-test="calling-counts">
      <li>فائتة اليوم: <b>{{ state.missed?.today ?? 0 }}</b></li>
      <li>خلال 7 أيام: <b>{{ state.missed?.week ?? 0 }}</b></li>
      <li v-if="state.rejected?.week" class="text-amber-700 dark:text-amber-400">أحداث مرفوضة (رقم آخر أو ناقصة) خلال 7 أيام: <b>{{ state.rejected.week }}</b></li>
    </ul>

    <!-- طريقة الرد والرفض الآلي ونص المتابعة -->
    <form @submit.prevent="save" class="space-y-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label for="calls-answer-mode" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">طريقة الرد المعتمدة</label>
          <select id="calls-answer-mode" v-model="form.answer_mode"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white">
            <option v-for="m in state.answer_modes || ['none', 'webrtc', 'sip']" :key="m" :value="m">{{ MODES[m] || m }}</option>
          </select>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">الرد نفسه (WebRTC أو SIP) ملحمة مستقلة. «لا طريقة» تعني أن كل مكالمة تفوت.</p>
        </div>
        <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 cursor-pointer self-start">
          <input type="checkbox" v-model="form.auto_reject" data-test="calls-auto-reject" class="mt-0.5 w-4 h-4 rounded text-gold-500 shrink-0"/>
          <span class="min-w-0">
            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">رفض المكالمة فوراً ما دامت لا طريقة رد</span>
            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">ينتهي الرنين بدل أن يفوت، ثم تُرسل المتابعة النصية إن كانت مفعّلة.</span>
          </span>
        </label>
      </div>

      <div>
        <label for="calls-followup" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          نص المتابعة بعد المكالمة الفائتة
          <span v-if="!state.followup_enabled" class="ms-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">لا يُرسل: العلم calls_followup مطفأ</span>
        </label>
        <textarea id="calls-followup" v-model="form.followup_text" rows="3" dir="auto" :placeholder="state.followup?.default"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white resize-y [unicode-bidi:plaintext]"></textarea>
        <p v-if="errors.followup_text" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.followup_text }}</p>
        <p v-else class="text-xs text-gray-500 dark:text-gray-400 mt-1">
          فارغ = النص المقترح أعلاه. لا أسعار ولا بيانات دفع، والهاتف من المتغير
          <bdi dir="ltr" class="font-mono">{company.phone}</bdi> وحده. مرة كل {{ state.followup?.hours || 6 }} ساعات للمحادثة، داخل نافذة فتحها العميل باتصاله.
        </p>
      </div>
      <div>
        <label for="calls-followup-en" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">النص بالإنجليزية (اختياري: لمن كتب آخر مرة بحروف لاتينية)</label>
        <textarea id="calls-followup-en" v-model="form.followup_text_en" rows="2" dir="ltr"
                  class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white resize-y"></textarea>
        <p v-if="errors.followup_text_en" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.followup_text_en }}</p>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <button type="submit" data-test="calling-save" :disabled="saving"
                class="min-h-11 px-6 py-2 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
          {{ saving ? '⏳ جارٍ الحفظ…' : '💾 حفظ إعدادات المكالمات' }}
        </button>
        <p v-if="message" role="status" class="text-xs" :class="messageOk ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">{{ message }}</p>
      </div>
    </form>

    <p class="text-xs text-gray-500 dark:text-gray-400">
      تفعيل الاتصال عند ميتا أو إيقافه قرار نادر متعمد: بالأمر <bdi dir="ltr" class="font-mono">php artisan whatsapp:calling-settings --apply=…</bdi> بتأكيد، لا من هنا.
    </p>
  </section>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import { when } from '@/utils/waSettings.js';

const props = defineProps({ calling: { type: Object, required: true } });
const emit = defineEmits(['changed']);

const MODES = { none: 'لا طريقة رد (كل مكالمة تفوت)', webrtc: 'WebRTC من المتصفح', sip: 'SIP إلى مقسم' };
const SOURCES = { read: 'قراءة من ميتا', webhook: 'تحديث من ميتا (ويبهوك)', applied: 'آخر ما طُبّق بالأمر' };

const state = ref({ ...props.calling });
const snapshot = computed(() => state.value.snapshot);
const enabled = computed(() => String(snapshot.value?.calling?.status || '').toUpperCase() === 'ENABLED');
const hours = computed(() => {
  const h = snapshot.value?.calling?.call_hours;
  if (!h) return '—';
  return [h.status, h.timezone_id].filter(Boolean).join(' · ') || '—';
});

const fromState = (s) => ({
  answer_mode: s.answer_mode || 'none',
  auto_reject: !!s.auto_reject,
  followup_text: s.followup?.text || '',
  followup_text_en: s.followup?.text_en || '',
});
const form = reactive(fromState(props.calling));

const reading = ref(false);
const saving = ref(false);
const errors = ref({});
const message = ref('');
const messageOk = ref(false);

const adopt = (data) => {
  state.value = { ...data.calling };
  Object.assign(form, fromState(data.calling));
};

const refresh = async () => {
  reading.value = true;
  message.value = '';
  try {
    const { data } = await axios.post('/api/whatsapp/calling/refresh', {}, { headers: { Accept: 'application/json' } });
    state.value = { ...data.calling };
    messageOk.value = true;
    message.value = '✅ قُرئت الإعدادات من ميتا';
  } catch (e) {
    messageOk.value = false;
    message.value = e.response?.status === 429 ? '❌ قراءات كثيرة — انتظر دقيقة' : '❌ ' + (e.response?.data?.message || 'تعذّرت القراءة');
  } finally {
    reading.value = false;
  }
};

const save = async () => {
  saving.value = true;
  errors.value = {};
  message.value = '';
  try {
    const { data } = await axios.put('/api/whatsapp/calling/settings', { ...form }, { headers: { Accept: 'application/json' } });
    adopt(data);
    messageOk.value = true;
    message.value = '✅ حُفظت';
    emit('changed');
  } catch (e) {
    messageOk.value = false;
    if (e.response?.status === 422) {
      errors.value = Object.fromEntries(Object.entries(e.response.data.errors || {}).map(([k, v]) => [k, v[0]]));
    } else {
      message.value = '❌ ' + (e.response?.data?.message || 'تعذّر الحفظ');
    }
  } finally {
    saving.value = false;
  }
};
</script>
