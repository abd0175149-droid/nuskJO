<template>
  <!-- إضافات الفقاعة (E29): ترجمة الوارد عند الطلب، ووسم الصادر المترجم ونص الموظف الأصلي، والتثبيت الداخلي. نص فقط بلا v-html -->
  <div v-if="hasContent" class="mt-1 space-y-1" data-test="bubble-extras">
    <div v-if="translate && m.translation" class="rounded-lg border border-dashed border-gray-400/70 dark:border-gray-500/70 px-2 py-1" data-test="translation">
      <p dir="auto" class="text-sm whitespace-pre-line break-words [unicode-bidi:plaintext]">{{ m.translation.text }}</p>
      <p class="text-[10px] opacity-70">🌐 {{ sourceLine }}</p>
    </div>

    <div v-if="m.translated" class="text-[10px] opacity-90" data-test="translated-tag">
      <span>🌐 أُرسلت مترجمة إلى {{ m.translated.to_label || m.translated.to }}</span>
      <details v-if="m.translated.source_text" class="mt-0.5">
        <summary class="cursor-pointer select-none font-bold">نصّك الأصلي ▾</summary>
        <p dir="auto" class="mt-0.5 whitespace-pre-line break-words [unicode-bidi:plaintext]">{{ m.translated.source_text }}</p>
      </details>
    </div>

    <div v-if="showTranslate || showPin" class="flex flex-wrap items-center gap-1.5">
      <button v-if="showTranslate" type="button" :disabled="!!busy" @click="doTranslate" data-test="translate-btn"
              class="min-h-8 px-2 rounded-lg text-[11px] font-bold bg-white/85 text-gray-800 dark:bg-gray-900 dark:text-gray-100 border border-black/10 dark:border-white/10 disabled:opacity-60">
        {{ busy === 'translate' ? '⏳ جارٍ الترجمة…' : '🌐 ترجم' }}
      </button>
      <button v-if="showPin" type="button" :disabled="!!busy" @click="togglePin" data-test="pin-btn"
              :aria-label="pinned ? 'إلغاء التثبيت الداخلي' : 'تثبيت داخلي (لا يراه العميل)'" :title="pinned ? 'إلغاء التثبيت الداخلي' : 'تثبيت داخلي (لا يراه العميل)'"
              class="min-h-8 px-1.5 rounded-lg text-[11px] disabled:opacity-60" :class="pinned ? 'font-bold' : 'opacity-60 hover:opacity-100'">
        📌<span v-if="pinned"> مثبّتة</span>
      </button>
    </div>
    <p v-if="error" class="text-[10px] font-bold text-red-700 dark:text-red-300" role="alert">{{ error }}</p>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { errorText } from '@/composables/whatsapp/useInbox.js';
import { languageName } from '@/composables/whatsapp/useLabels.js';

const props = defineProps({
  m: { type: Object, required: true },
  conversationId: { type: Number, required: true },
  canReply: Boolean,
  // الأعلام staff_translation وthread_pins
  translate: Boolean,
  pins: Boolean,
  pinned: Boolean,
});
const emit = defineEmits(['translated', 'pinned']);

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const busy = ref('');
const error = ref('');

const showTranslate = computed(() => props.translate && props.m.direction === 'in' && !!props.m.can_translate && !props.m.translation);
const showPin = computed(() => props.pins && props.canReply && !props.m.is_system && props.m.type !== 'reaction');
const hasContent = computed(() => (props.translate && props.m.translation) || props.m.translated || showTranslate.value || showPin.value || error.value);
const sourceLine = computed(() => {
  const lang = languageName(props.m.translation?.src);
  return `ترجمة آلية${lang ? ` من ${lang}` : ''} — قد لا تكون دقيقة`;
});

async function doTranslate() {
  busy.value = 'translate';
  error.value = '';
  try {
    const { data } = await axios.post(`/api/whatsapp/conversations/${props.conversationId}/messages/${props.m.id}/translate`, {}, api);
    emit('translated', { text: data.text, src: data.src_lang });
  } catch (e) {
    error.value = errorText(e, 'تعذّرت الترجمة');
  } finally {
    busy.value = '';
  }
}

async function togglePin() {
  busy.value = 'pin';
  error.value = '';
  const path = `/api/whatsapp/conversations/${props.conversationId}/messages/${props.m.id}/pin`;
  try {
    const { data } = props.pinned ? await axios.delete(path, api) : await axios.post(path, {}, api);
    emit('pinned', data.pinned);
  } catch (e) {
    error.value = errorText(e, 'تعذّر التثبيت');
  } finally {
    busy.value = '';
  }
}
</script>
