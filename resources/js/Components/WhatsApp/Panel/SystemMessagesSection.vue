<template>
  <!-- رسائل النظام للعميل (2026-11-06): القادمة والأخيرة على كل أرقامنا، والكتم، وجدولة متابعة بنص الموظف -->
  <div class="space-y-3 text-xs" data-test="panel-system">
    <div>
      <p class="text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-1">القادمة</p>
      <ul v-if="system.upcoming.length" class="space-y-1">
        <li v-for="m in system.upcoming" :key="m.id" class="flex justify-between gap-2">
          <span>{{ m.type }}<span v-if="m.status === 'deferred'" class="text-amber-700" :title="m.reason"> · مؤجلة</span><span v-if="m.status === 'awaiting_approval'" class="text-sky-700"> · تنتظر موافقة</span></span>
          <bdi dir="ltr" class="text-gray-400 shrink-0">{{ fmt(m.at) }}</bdi>
        </li>
      </ul>
      <p v-else class="text-gray-400">لا شيء مجدول.</p>
    </div>
    <div v-if="system.recent.length">
      <p class="text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-1">الأخيرة</p>
      <ul class="space-y-1">
        <li v-for="m in system.recent" :key="m.id" class="flex justify-between gap-2">
          <span>{{ m.type }} <span :class="m.status === 'sent' ? 'text-green-700' : 'text-red-600'">{{ m.status === 'sent' ? '✓' : '✗' }}</span></span>
          <bdi dir="ltr" class="text-gray-400 shrink-0">{{ fmt(m.at) }}</bdi>
        </li>
      </ul>
    </div>
    <p v-if="system.muted.length" class="text-amber-800 dark:text-amber-300">🔕 مكتوم: {{ system.muted.join('، ') }}</p>

    <div v-if="canReply" class="flex flex-wrap gap-1.5">
      <button v-if="system.can_follow_up" type="button" class="wp-act" @click="open = !open" data-test="panel-follow-up">⏰ جدولة متابعة</button>
      <button v-if="linked" type="button" class="wp-act" @click="toggleMute">{{ system.muted.includes('كل الرسائل') ? '🔔 رفع الكتم' : '🔕 كتم الرسائل الآلية' }}</button>
    </div>
    <form v-if="open" class="space-y-2 rounded-xl border border-gray-200 dark:border-gray-700 p-2" @submit.prevent="schedule">
      <textarea v-model="text" rows="3" maxlength="1500" required placeholder="نص المتابعة كما يصل العميل" aria-label="نص المتابعة"
                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-xs"></textarea>
      <div class="flex gap-2 items-center">
        <input v-model="at" type="datetime-local" required :min="minAt" aria-label="موعد المتابعة" class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-xs" dir="ltr" />
        <button type="submit" :disabled="busy" class="px-3 py-1.5 rounded-lg font-bold text-black bg-gold-400 disabled:opacity-40">جدولة</button>
      </div>
      <p class="text-[10px] text-gray-400">تُلغى وحدها إن ردّ العميل قبل موعدها، وتخرج بحدود الإرسال.</p>
    </form>
    <p v-if="msg" role="status" :class="msg.ok ? 'text-green-700' : 'text-red-600'">{{ msg.text }}</p>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  conversationId: { type: Number, required: true },
  system: { type: Object, default: () => ({ upcoming: [], recent: [], muted: [], can_follow_up: false }) },
  linked: Boolean,
  canReply: Boolean,
});
const emit = defineEmits(['changed']);

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const open = ref(false);
const text = ref('');
const busy = ref(false);
const msg = ref(null);
const local = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const minAt = local(new Date(Date.now() + 10 * 60000));
const at = ref(local(new Date(Date.now() + 24 * 3600000)));
const fmt = (iso) => (iso ? new Date(iso).toLocaleString('ar-JO-u-nu-latn', { dateStyle: 'short', timeStyle: 'short' }) : '');

async function schedule() {
  busy.value = true;
  msg.value = null;
  try {
    const { data } = await axios.post(`/api/whatsapp/conversations/${props.conversationId}/follow-up`, { text: text.value, at: at.value }, api);
    msg.value = { ok: true, text: data.note || 'جُدولت المتابعة' };
    open.value = false;
    text.value = '';
    emit('changed');
  } catch (e) {
    msg.value = { ok: false, text: errorText(e, 'تعذّرت الجدولة') };
  } finally {
    busy.value = false;
  }
}

async function toggleMute() {
  const mute = !props.system.muted.includes('كل الرسائل');
  try {
    await axios.post(`/api/whatsapp/conversations/${props.conversationId}/system-mute`, { mute }, api);
    emit('changed');
  } catch (e) {
    msg.value = { ok: false, text: errorText(e, 'تعذّر الحفظ') };
  }
}
</script>
