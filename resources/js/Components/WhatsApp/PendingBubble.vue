<template>
  <!-- فقاعة ملف متفائلة (E32.10): تظهر لحظة التأكيد بمعاينة وشريط تقدم، وتُزال حين يظهر صفها في الخيط -->
  <div class="flex justify-end" data-test="pending-bubble">
    <div class="max-w-[85%] sm:max-w-[78%] rounded-2xl rounded-se-sm px-3.5 py-2 text-sm shadow-sm bg-gold-100 dark:bg-gold-900/40 text-gray-900 dark:text-gray-100 border border-gold-300 dark:border-gold-700">
      <div class="flex items-center gap-2 min-w-0">
        <img v-if="item.preview" :src="item.preview" alt="" class="w-12 h-12 shrink-0 rounded-lg object-cover"/>
        <span v-else aria-hidden="true" class="text-2xl shrink-0">{{ KIND_ICONS[item.kind] || '📎' }}</span>
        <div class="min-w-0">
          <bdi dir="auto" class="block truncate font-medium">{{ item.name }}</bdi>
          <span dir="ltr" class="text-[11px] opacity-70">{{ formatSize(item.size) }}</span>
        </div>
      </div>
      <p v-if="item.caption" dir="auto" class="mt-1 break-words [unicode-bidi:plaintext]">{{ item.caption }}</p>

      <div v-if="item.state === 'uploading'" class="mt-1.5" role="progressbar" aria-label="تقدم الرفع" :aria-valuenow="item.progress" aria-valuemin="0" aria-valuemax="100">
        <div class="h-1.5 rounded-full bg-black/10 dark:bg-white/10 overflow-hidden">
          <div class="h-full bg-gold-500 transition-[width]" :style="{ width: `${item.progress}%` }"></div>
        </div>
        <p class="mt-0.5 text-[11px]" data-test="state-uploading">⏫ قيد الإرسال… <bdi dir="ltr">{{ item.progress }}%</bdi></p>
      </div>
      <p v-else-if="item.state === 'queued'" class="mt-1 text-[11px]" data-test="state-queued">⏳ في الطابور — يُرفع إلى واتساب الآن</p>
      <p v-else-if="item.state === 'sent'" class="mt-1 text-[11px]">✓ أُرسل</p>
      <template v-else-if="item.state === 'network'">
        <p role="alert" class="mt-1 text-[11px] font-bold text-red-700 dark:text-red-300">⚠️ {{ item.error }}</p>
        <div class="mt-1 flex flex-wrap gap-1.5">
          <button type="button" data-test="outbox-retry" @click="$emit('retry', item)"
                  class="min-h-8 px-2 rounded-lg text-[11px] font-bold bg-white/85 text-gray-800 dark:bg-gray-900 dark:text-gray-100 border border-black/10 dark:border-white/10">🔁 إعادة المحاولة</button>
          <button type="button" @click="$emit('dismiss', item)"
                  class="min-h-8 px-2 rounded-lg text-[11px] border border-black/10 dark:border-white/10">إلغاء</button>
        </div>
      </template>
      <p v-if="item.warning" class="mt-1 text-[11px] opacity-80">ℹ️ {{ item.warning }}</p>
    </div>
  </div>
</template>

<script setup>
import { KIND_ICONS, formatSize } from '@/utils/media.js';

defineProps({ item: { type: Object, required: true } });
defineEmits(['retry', 'dismiss']);
</script>
