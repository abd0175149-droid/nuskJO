<template>
  <!-- التعايش مع تطبيق الأعمال على الهاتف (E39، أعلام coexistence_*): الحامل ومدة إيقاف البوت والأعداد وحالة الربط -->
  <section id="coexistence" data-test="coexistence-card" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 md:p-6 space-y-4 scroll-mt-20">
    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">📱 التعايش مع تطبيق الأعمال</h3>

    <ul class="flex flex-wrap gap-2 text-xs" data-test="coexistence-flags">
      <li v-for="f in FLAGS" :key="f.key" class="px-2 py-0.5 rounded-full font-bold"
          :class="state.flags?.[f.key] ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'">
        {{ state.flags?.[f.key] ? '✅' : '⏸️' }} {{ f.label }}
      </li>
    </ul>
    <p v-if="state.flags?.guard && !state.flags?.echoes" class="text-xs text-gray-600 dark:text-gray-300">
      الحارس وحده: أي رسالة تُكتب من الهاتف توقف البوت في محادثتها ساعة على الأقل، ولا يُحفظ نصها.
    </p>

    <p v-if="state.link?.state === 'removed'" role="alert" data-test="coexistence-link-removed"
       class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-3 py-2 text-xs font-bold text-amber-800 dark:text-amber-300">
      📱 انفصل ربط التعايش — افتح تطبيق واتساب أعمال على الهاتف وأعد الربط.
    </p>

    <form @submit.prevent="save" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div>
        <label for="coex-holder" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">حامل جهاز التعايش</label>
        <select id="coex-holder" v-model="form.holder_user_id"
                class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white">
          <option :value="null">— بلا حامل (الرسائل بلا اسم مرسل)</option>
          <option v-for="u in state.candidates || []" :key="u.id" :value="u.id">{{ u.name }}</option>
        </select>
        <p v-if="errors.holder_user_id" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.holder_user_id }}</p>
        <p v-else-if="state.holder_id && !state.holder_valid" class="text-xs font-bold text-amber-700 dark:text-amber-400 mt-1">⚠️ الحامل الحالي لم يعد يرى كل المحادثات أو عُطّل — اختر غيره.</p>
        <p v-else class="text-xs text-gray-500 dark:text-gray-400 mt-1">مستخدم واحد يرى كل المحادثات (الهاتف يراها كلها). التغيير يُدقَّق.</p>
      </div>
      <div>
        <label for="coex-pause" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">إيقاف البوت بعد رسالة من الهاتف (دقائق)</label>
        <input id="coex-pause" v-model.number="form.echo_pause_minutes" type="number" min="1" max="1440" dir="ltr" :placeholder="String(state.pause_minutes || '')"
               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"/>
        <p v-if="errors.echo_pause_minutes" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.echo_pause_minutes }}</p>
        <p v-else class="text-xs text-gray-500 dark:text-gray-400 mt-1">فارغ = مدة الإيقاف بعد رد موظف ({{ state.pause_minutes }} دقيقة).</p>
      </div>
      <div class="sm:col-span-2 flex flex-wrap items-center gap-3">
        <button type="submit" data-test="coexistence-save" :disabled="saving"
                class="min-h-11 px-6 py-2 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
          {{ saving ? '⏳ جارٍ الحفظ…' : '💾 حفظ' }}
        </button>
        <p v-if="message" role="status" class="text-xs" :class="messageOk ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">{{ message }}</p>
      </div>
    </form>

    <ul class="space-y-1 text-xs text-gray-700 dark:text-gray-200" data-test="coexistence-counts">
      <li>رسائل من الهاتف اليوم: <b>{{ state.echoes_today ?? 0 }}</b></li>
      <li v-if="state.history?.last">
        آخر سجل سابق وصل: <b>{{ state.history.last.threads }}</b> محادثة و<b>{{ state.history.last.messages }}</b> رسالة ·
        <bdi dir="ltr">{{ when(state.history.last.at) }}</bdi>
      </li>
      <li v-if="state.history?.hold?.held" class="font-bold text-amber-700 dark:text-amber-400" data-test="history-held">
        محفوظ مشفّراً بانتظار قرار الاستيراد: {{ state.history.hold.held }} دفعة ({{ state.history.hold.messages }} رسالة)، يُمحى في
        <bdi dir="ltr">{{ when(state.history.hold.purge_soonest) }}</bdi> — <bdi dir="ltr" class="font-mono">whatsapp:coexistence-history</bdi>
      </li>
      <li v-if="state.state_sync">جهات اتصال التطبيق: {{ state.state_sync.total }} (عدد فقط، لا تُستورد الأسماء)</li>
    </ul>
  </section>
</template>

<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';
import { when } from '@/utils/waSettings.js';

const props = defineProps({ coexistence: { type: Object, required: true } });
const emit = defineEmits(['changed']);

const FLAGS = [
  { key: 'guard', label: 'الحارس المبكر' },
  { key: 'echoes', label: 'حفظ رسائل الهاتف' },
  { key: 'history_hold', label: 'حفظ السجل السابق' },
];

const state = ref({ ...props.coexistence });
const fromState = (s) => ({ holder_user_id: s.holder_id ?? null, echo_pause_minutes: s.echo_pause_minutes ?? null });
const form = reactive(fromState(props.coexistence));
const saving = ref(false);
const errors = ref({});
const message = ref('');
const messageOk = ref(false);

const save = async () => {
  saving.value = true;
  errors.value = {};
  message.value = '';
  try {
    const payload = { holder_user_id: form.holder_user_id || null, echo_pause_minutes: form.echo_pause_minutes === '' ? null : (form.echo_pause_minutes ?? null) };
    const { data } = await axios.put('/api/whatsapp/coexistence/settings', payload, { headers: { Accept: 'application/json' } });
    state.value = { ...data.coexistence };
    Object.assign(form, fromState(data.coexistence));
    messageOk.value = true;
    message.value = '✅ حُفظ';
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
