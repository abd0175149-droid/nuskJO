<template>
  <!-- تصنيفات المحادثة في رأس الخيط (E29): داخلية، لا تغيّر من يرى المحادثة. الاختيار من القائمة وحدها -->
  <div class="px-3 sm:px-4 py-1.5 border-b border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-1.5 shrink-0" data-test="thread-labels">
    <span class="text-[11px] text-gray-400 dark:text-gray-500">🏷️ التصنيفات:</span>
    <span v-for="l in current" :key="l.id" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] leading-4 ring-1"
          :class="labelClass(l.color, l.active)" :title="l.active === false ? 'تصنيف معطّل' : 'تصنيف داخلي'">
      <bdi dir="auto">{{ l.name }}</bdi>
      <button v-if="canReply" type="button" :disabled="busy" @click="remove(l)" :aria-label="`إزالة تصنيف ${l.name}`" :title="`إزالة تصنيف ${l.name}`"
              class="leading-none hover:text-red-600 dark:hover:text-red-400 disabled:opacity-40">✕</button>
    </span>
    <span v-if="!current.length" class="text-[11px] text-gray-400 dark:text-gray-500">بلا تصنيف</span>
    <button v-if="canReply && (all.length || canManage)" type="button" @click="open = true"
            class="min-h-8 px-2 py-0.5 rounded-lg text-[11px] font-bold border border-gold-400 text-gold-700 dark:text-gold-400">+ تصنيف</button>
    <p v-if="error" class="w-full text-[11px] text-red-600" role="alert">❌ {{ error }}</p>
    <LabelPicker :open="open" :conversation-id="conversationId" :labels="all" :selected="current" :can-manage="canManage"
                 @changed="(l) => $emit('changed', l)" @close="open = false"/>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import LabelPicker from './LabelPicker.vue';
import { errorText } from '@/composables/whatsapp/useInbox.js';
import { labelClass, setLabel } from '@/composables/whatsapp/useLabels.js';

const props = defineProps({
  conversationId: { type: Number, required: true },
  current: { type: Array, default: () => [] },
  all: { type: Array, default: () => [] },
  canReply: Boolean,
  canManage: Boolean,
});
const emit = defineEmits(['changed']);

const open = ref(false);
const busy = ref(false);
const error = ref('');
watch(() => props.conversationId, () => { open.value = false; error.value = ''; });

async function remove(l) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    emit('changed', await setLabel(props.conversationId, l.id, false));
  } catch (e) {
    error.value = errorText(e, 'تعذّرت إزالة التصنيف');
  } finally {
    busy.value = false;
  }
}
</script>
