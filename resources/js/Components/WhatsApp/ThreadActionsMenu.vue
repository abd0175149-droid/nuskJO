<template>
  <!-- إجراءات المتابعة (E17): كلها حالة داخلية عندنا، لا شيء يصل العميل أو ميتا. على الجوال لوحة بعرض الشاشة لا تُقصّ -->
  <div ref="root" class="relative" @keydown.escape.stop="open = false">
    <button type="button" @click="toggle" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="menu" aria-label="إجراءات المحادثة" title="إجراءات المحادثة"
            class="min-w-11 min-h-11 lg:min-h-0 lg:min-w-0 px-2.5 py-1 rounded-lg text-sm font-bold border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">⋯</button>
    <div v-if="open" role="menu" aria-label="إجراءات المحادثة"
         class="fixed inset-x-4 top-24 z-40 max-h-[calc(100dvh-8rem)] overflow-y-auto sm:absolute sm:inset-x-auto sm:end-0 sm:top-full sm:mt-1 sm:w-72 sm:max-h-none rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-xl p-1.5 text-sm">
      <template v-if="canReply">
        <button v-if="!thread.closed" type="button" role="menuitem" class="item" @click="act('close')">🗂️ إغلاق المحادثة</button>
        <button v-else type="button" role="menuitem" class="item" @click="act('reopen')">📂 إعادة فتح المحادثة</button>

        <div class="px-2.5 pt-2 pb-1 text-[11px] font-bold text-gray-500 dark:text-gray-400">💤 تأجيل</div>
        <template v-if="options.length">
          <button v-for="o in options" :key="o.key" type="button" role="menuitem" class="item" :disabled="o.disabled" :title="o.hint"
                  @click="act('snooze', { minutes: o.minutes })">{{ o.label }}<span v-if="o.hint" class="block text-[10px] text-gray-400">{{ o.hint }}</span></button>
          <form class="flex items-center gap-1.5 px-2.5 py-1.5" @submit.prevent="custom && act('snooze', { until: new Date(custom).toISOString() })">
            <label :for="customId" class="sr-only">وقت مخصص</label>
            <input :id="customId" v-model="custom" type="datetime-local" :min="bounds?.min" :max="bounds?.max" dir="ltr"
                   class="flex-1 min-w-0 px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs"/>
            <button type="submit" :disabled="!custom" class="px-2 py-1 rounded-lg text-xs font-bold border border-gold-400 text-gold-700 dark:text-gold-400 disabled:opacity-40">تأجيل</button>
          </form>
        </template>
        <p v-else class="px-2.5 py-1.5 text-[11px] text-gray-400">لا يمكن التأجيل بعد إغلاق النافذة — لا قوالب لدينا</p>
        <button v-if="thread.snoozed_until" type="button" role="menuitem" class="item" @click="act('unsnooze')">⏰ إلغاء التأجيل</button>
        <hr class="my-1 border-gray-100 dark:border-gray-800"/>
      </template>

      <button type="button" role="menuitem" class="item" @click="act(thread.pinned ? 'unpin' : 'pin')">{{ thread.pinned ? '📍 إلغاء التثبيت' : '📍 تثبيت أعلى القائمة' }}</button>
      <button type="button" role="menuitem" class="item" @click="act('markUnread')">📌 تعليم كغير مقروءة<span class="block text-[10px] text-gray-400">علامة داخلية لا يراها العميل</span></button>
      <button v-if="canReprocess && thread.stuck" type="button" role="menuitem" class="item text-purple-700 dark:text-purple-300" @click="act('reprocess')">🔁 إعادة معالجة الرد العالق</button>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { customBounds, snoozeOptions } from '@/composables/whatsapp/useSnoozeOptions.js';

const props = defineProps({
  thread: { type: Object, required: true },
  canReply: Boolean,
  canReprocess: Boolean,
});
const emit = defineEmits(['action']);

const open = ref(false);
const root = ref(null);
const custom = ref('');
const customId = `snooze-custom-${useId()}`;
const options = computed(() => snoozeOptions(props.thread.window_left));
const bounds = computed(() => customBounds(props.thread.window_left));

watch(() => props.thread.id, () => { open.value = false; custom.value = ''; });

const toggle = () => { open.value = !open.value; };
function act(name, payload) {
  open.value = false;
  emit('action', name, payload);
}

const outside = (e) => { if (open.value && root.value && !root.value.contains(e.target)) open.value = false; };
onMounted(() => document.addEventListener('pointerdown', outside));
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside));
</script>

<style scoped>
.item {
  display: block;
  width: 100%;
  text-align: start;
  padding: 0.5rem 0.625rem;
  border-radius: 0.5rem;
  min-height: 2.75rem;
}
.item:hover:not(:disabled) { background: rgb(0 0 0 / 0.05); }
:global(.dark) .item:hover:not(:disabled) { background: rgb(255 255 255 / 0.06); }
.item:disabled { opacity: 0.45; cursor: not-allowed; }
</style>
