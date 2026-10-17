<template>
  <!-- محتوى الفقاعة الغني (E33): الموقع ببطاقة ورابط خرائط خارجي (بلا خريطة مضمّنة ولا سكربت)، وبطاقات الاتصال بالاسم
       (والرقم لمن يملك الرد، يقرره الخادم)، وشارة «أُرسل تصحيح ↓»، وشارات التفاعل. نص فقط بلا v-html -->
  <div v-if="hasContent" class="mt-1 space-y-1" data-test="message-rich">
    <div v-if="m.location" class="rounded-lg border border-black/10 dark:border-white/10 bg-white/70 dark:bg-gray-900/60 text-gray-800 dark:text-gray-100 px-2.5 py-1.5" data-test="location-card">
      <p dir="auto" class="text-xs font-bold break-words [unicode-bidi:plaintext]">📍 {{ m.location.name || 'موقع' }}</p>
      <p v-if="m.location.address" dir="auto" class="text-[11px] opacity-80 break-words [unicode-bidi:plaintext]">{{ m.location.address }}</p>
      <a :href="m.location.maps_url" target="_blank" rel="noopener noreferrer nofollow" data-test="maps-link"
         class="inline-flex items-center min-h-8 text-[11px] font-bold text-gold-700 dark:text-gold-400 underline">🗺️ فتح في الخرائط</a>
    </div>

    <ul v-if="m.contact_cards?.length" class="space-y-1" data-test="contact-cards">
      <li v-for="(c, i) in m.contact_cards" :key="i" class="rounded-lg border border-black/10 dark:border-white/10 bg-white/70 dark:bg-gray-900/60 text-gray-800 dark:text-gray-100 px-2.5 py-1.5">
        <p dir="auto" class="text-xs font-bold break-words [unicode-bidi:plaintext]">👤 {{ c.name }}</p>
        <p v-for="(p, j) in c.phones || []" :key="j" class="text-[11px]"><bdi dir="ltr" class="font-mono">{{ p }}</bdi></p>
      </li>
    </ul>

    <p v-if="m.corrects_id" class="text-[10px] font-bold opacity-90">
      <button type="button" class="underline" data-test="corrects-link" @click="$emit('jump', m.corrects_id)">✏️ تصحيح لرسالة سابقة ↑</button>
    </p>
    <p v-if="correctedBy" class="text-[10px] font-bold" data-test="corrected-badge">
      <button type="button" class="px-1.5 rounded bg-white/85 text-gray-800 dark:bg-gray-900 dark:text-gray-100 underline" @click="$emit('jump', correctedBy)">أُرسل تصحيح ↓</button>
    </p>

    <p v-if="m.type === 'reaction' && m.orphan" class="text-[10px] opacity-75" data-test="orphan-reaction">تفاعل على رسالة غير معروضة</p>

    <div v-if="reactions.customer || reactions.business" class="flex flex-wrap gap-1" data-test="reaction-badges">
      <span v-if="reactions.customer" class="inline-flex items-center gap-1 px-1.5 rounded-full text-xs bg-white/90 text-gray-800 dark:bg-gray-900 dark:text-gray-100 border border-black/10 dark:border-white/10"
            title="تفاعل العميل" data-test="reaction-customer">{{ reactions.customer }}</span>
      <span v-if="reactions.business" class="inline-flex items-center gap-1 px-1.5 rounded-full text-xs bg-white/90 text-gray-800 dark:bg-gray-900 dark:text-gray-100 border border-black/10 dark:border-white/10"
            title="تفاعل الموظف" data-test="reaction-business">{{ reactions.business }} <span class="text-[10px]">الموظف</span></span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  m: { type: Object, required: true },
  // حالة التفاعلات الأحدث (الاستطلاع يحمل صف التفاعل بحالة هدفه)، وإلا ما في الرسالة نفسها
  reactions: { type: Object, default: null },
  // تصحيح وصل في الاستطلاع ويشير إلى هذه الرسالة
  correctedById: { type: Number, default: null },
});
defineEmits(['jump']);

const reactions = computed(() => props.reactions || props.m.reactions || {});
const correctedBy = computed(() => props.correctedById || (props.m.corrected_by?.length ? props.m.corrected_by[props.m.corrected_by.length - 1] : null));
const hasContent = computed(() => !!(props.m.location || props.m.contact_cards?.length || props.m.corrects_id || correctedBy.value
  || (props.m.type === 'reaction' && props.m.orphan) || reactions.value.customer || reactions.value.business));
</script>
