<template>
  <!-- مقتطف نتيجة البحث (E30.7): أجزاء نصية من الخادم، والمطابق داخل <mark>. نص العميل يُرسم نصاً مهرَّباً دائماً -->
  <span dir="auto" class="[unicode-bidi:plaintext] break-words"><template v-for="(s, i) in segments" :key="i"><mark v-if="s.hl" class="bg-gold-200 dark:bg-gold-700/50 text-inherit rounded px-0.5">{{ s.t }}</mark><span v-else>{{ s.t }}</span></template></span>
</template>

<script setup>
defineProps({ segments: { type: Array, default: () => [] } });
</script>
