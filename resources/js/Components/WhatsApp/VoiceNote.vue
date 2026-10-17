<template>
  <!-- الرسالة الصوتية في الخيط (E34.8): مشغّل لا يحمّل الملف إلا عند الضغط (preload=none) من مسار المرفقات (E14: سياجه
       وصلاحية whatsapp.media وتدقيق الاطّلاع)، والمدة، والتفريغ للزملاء مطوياً. نص فقط بلا v-html -->
  <div class="mt-1.5 rounded-xl border border-black/10 dark:border-white/10 bg-black/5 dark:bg-white/5 px-2.5 py-1.5 text-[11px] space-y-1" data-test="voice-note">
    <div class="flex items-center gap-1.5">
      <span aria-hidden="true">🎤</span>
      <span class="font-medium">رسالة صوتية</span>
      <span v-if="seconds" dir="ltr" class="opacity-70 tabular-nums">{{ clock(seconds) }}</span>
    </div>
    <audio v-if="media?.viewable" controls preload="none" :src="mediaUrl(messageId)" aria-label="تشغيل الرسالة الصوتية" data-test="voice-player"
           class="block w-64 max-w-full h-9"></audio>
    <p v-else-if="media" class="opacity-80" data-test="voice-locked">{{ statusLabel(media) || 'الاستماع لمن يملك صلاحية عرض المرفقات' }}</p>
    <details v-if="voice?.transcript" data-test="voice-transcript">
      <summary class="cursor-pointer font-bold select-none">📝 النص (تفريغ آلي للزملاء)</summary>
      <p dir="auto" class="mt-0.5 break-words whitespace-pre-line [unicode-bidi:plaintext]">{{ voice.transcript }}</p>
    </details>
    <p v-else-if="voice?.transcript_failed" class="opacity-70" data-test="voice-transcript-failed">{{ FAILED[voice.transcript_failed] || FAILED.error }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { mediaUrl, statusLabel } from '@/utils/media.js';
import { clock } from '@/composables/whatsapp/useVoiceRecorder.js';

const props = defineProps({
  messageId: { type: Number, required: true },
  media: { type: Object, default: null },
  // {seconds, transcript, transcript_failed} لرسالة الموظف؛ null لصوت العميل
  voice: { type: Object, default: null },
});

const seconds = computed(() => Number(props.voice?.seconds) || 0);

// أسباب تعذّر التفريغ (TranscribeStaffVoice)
const FAILED = {
  provider: 'بلا نص: مزوّد الذكاء الحالي لا يفرّغ الصوت',
  terms: 'بلا نص: التفريغ ينتظر تأكيد المدير للطبقة المدفوعة',
  no_key: 'بلا نص: لا مفتاح لمزوّد الذكاء',
  empty: 'بلا نص: لم يُفهم كلام من التسجيل',
  file: 'بلا نص: الملف غير متاح للتفريغ',
  error: 'بلا نص: تعذّر التفريغ الآلي',
};
FAILED.llm = FAILED.error;
FAILED.llm_down = FAILED.error;
</script>
