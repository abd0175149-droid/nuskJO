<template>
  <!-- الاقتباس فوق الفقاعة (E33): بمعرّفنا من المحادثة نفسها، والنقر ينقل إلى الأصل إن كان محمّلاً. نص فقط بلا v-html -->
  <button v-if="quote" type="button" data-test="quote-block" @click="$emit('jump', quote.id)" :title="'انتقل إلى الرسالة المقتبسة'"
          class="mb-1 block w-full min-w-0 text-start px-2 py-1 rounded-lg border-s-2 border-gray-400/60 bg-black/5 dark:bg-white/5 text-[11px] opacity-90 hover:opacity-100">
    <span class="block font-bold opacity-80">↩️ {{ quote.direction === 'in' ? 'ردّاً على العميل' : 'ردّاً على رسالتنا' }}</span>
    <span dir="auto" class="block break-words line-clamp-2 [unicode-bidi:plaintext]">{{ quote.snippet }}</span>
  </button>
  <p v-else-if="text" dir="auto" class="mb-1 px-2 py-1 rounded-lg border-s-2 border-gray-400/60 bg-black/5 dark:bg-white/5 text-[11px] opacity-80 break-words line-clamp-2 [unicode-bidi:plaintext]">↩️ {{ text }}</p>
  <p v-if="dropped" data-test="quote-dropped" class="mb-1 text-[10px] opacity-75">ℹ️ لم تظهر فقاعة الاقتباس عند العميل (الأصل قديم أو محذوف)</p>
</template>

<script setup>
defineProps({
  // reply_ref من الخادم {id, snippet, direction}، وtext المقتطف القديم (E11) إن لم يُحلّ
  quote: { type: Object, default: null },
  text: { type: String, default: '' },
  dropped: Boolean,
});
defineEmits(['jump']);
</script>
