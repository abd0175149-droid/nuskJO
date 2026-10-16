<template>
  <!-- إجراءات رسالة (E33): بالمرور على سطح المكتب (⋯) وبالضغط المطوّل على الجوال. للموظف الذي يملك الرد وحده -->
  <AppModal :open="open" title="إجراءات الرسالة" size="sm" @close="$emit('close')">
    <div v-if="message" class="space-y-3">
      <p dir="auto" class="text-xs text-gray-500 dark:text-gray-400 break-words line-clamp-3 [unicode-bidi:plaintext]">{{ snippet }}</p>
      <button v-if="message.can_quote" type="button" data-autofocus data-test="action-reply" @click="$emit('reply', message)"
              class="w-full min-h-11 px-3 rounded-xl text-sm font-bold text-start border border-gray-200 dark:border-gray-700 hover:border-gold-400">↩️ رد</button>
      <div v-if="message.can_react" data-test="action-react">
        <p class="mb-1 text-xs font-bold text-gray-600 dark:text-gray-300">😀 تفاعل</p>
        <ReactionPicker :emojis="emojis" :current="current" :busy="busy" @pick="(e) => $emit('react', message, e)"/>
        <p class="mt-1 text-[11px] text-gray-400">يصل العميلَ كإقرار، ولا يُعدّ رداً ولا يوقف البوت.</p>
      </div>
      <button v-if="message.can_correct" type="button" data-test="action-correct" @click="$emit('correct', message)"
              class="w-full min-h-11 px-3 rounded-xl text-sm font-bold text-start border border-gray-200 dark:border-gray-700 hover:border-gold-400">✏️ إرسال تصحيح</button>
      <p v-if="error" role="alert" class="text-xs font-bold text-red-600 dark:text-red-400">❌ {{ error }}</p>
    </div>
  </AppModal>
</template>

<script setup>
import { computed } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import ReactionPicker from './ReactionPicker.vue';
import { snippetOf } from '@/composables/whatsapp/useReplyTarget.js';

const props = defineProps({
  open: Boolean,
  message: { type: Object, default: null },
  emojis: { type: Array, default: () => [] },
  // تفاعل الموظف الحالي على هذه الرسالة
  current: { type: String, default: '' },
  busy: Boolean,
  error: { type: String, default: '' },
});
defineEmits(['close', 'reply', 'react', 'correct']);

const snippet = computed(() => snippetOf(props.message));
</script>
