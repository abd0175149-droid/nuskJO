<template>
  <!-- لوحة إيموجي ثابتة (E32.10): رموز شائعة في محادثات الوكالة، بلا مكتبة ولا شبكة. تُدرج عند موضع المؤشر -->
  <div role="dialog" aria-label="رموز تعبيرية" @keydown.esc.stop="$emit('close')"
       class="absolute bottom-full mb-2 start-0 z-30 w-72 max-w-[calc(100vw-2rem)] p-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-xl">
    <div class="grid grid-cols-8 gap-1">
      <button v-for="e in EMOJIS" :key="e" type="button" :aria-label="e" @click="$emit('pick', e)"
              class="min-h-9 rounded-lg text-xl leading-none hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gold-400">{{ e }}</button>
    </div>
  </div>
</template>

<script setup>
defineEmits(['pick', 'close']);

const EMOJIS = [
  '🙏', '👍', '✅', '🕋', '🕌', '✈️', '🏨', '📄', '📍', '⏰', '🌙', '❤️', '😊', '🤝', '👋', '🎉',
  '📞', '📅', '🧾', '💳', '🛂', '🧳', '🚌', '🍽️', '⭐', '👌', '🙂', '😀', '😃', '🤲', '☺️', '💐',
  '⚠️', '❗', '❓', 'ℹ️', '📌', '🔔', '✔️', '➡️',
];
</script>
