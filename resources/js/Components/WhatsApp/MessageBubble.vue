<template>
  <div class="flex" :class="m.is_system ? 'justify-center' : (m.direction === 'in' ? 'justify-start' : 'justify-end')">
    <!-- تحديث هوية من واتساب (E11): ليس رسالة من العميل -->
    <div v-if="m.is_system" dir="auto" class="max-w-[85%] rounded-full px-3 py-1 text-[11px] text-gray-600 dark:text-gray-300 bg-gray-200 dark:bg-gray-800 [unicode-bidi:plaintext]">
      {{ m.body }} <span dir="ltr" class="opacity-60">· {{ m.at }}</span>
    </div>
    <div v-else class="max-w-[85%] sm:max-w-[78%] rounded-2xl px-3.5 py-2 text-sm shadow-sm" :class="tone">
      <p v-if="m.sender" dir="auto" class="text-[10px] font-bold opacity-80 mb-0.5 break-words">👤 {{ m.sender }}</p>
      <!-- الاقتباس (E11، ويكمله E33) -->
      <slot name="quote">
        <p v-if="m.reply_to" dir="auto" class="mb-1 px-2 py-1 rounded-lg border-s-2 border-gray-400/60 bg-black/5 dark:bg-white/5 text-[11px] opacity-80 break-words line-clamp-2 [unicode-bidi:plaintext]">↩️ {{ m.reply_to }}</p>
      </slot>
      <WaText :text="m.body || ''"/>
      <!-- المرفق (E14) -->
      <slot name="media"/>
      <div class="flex items-center gap-1.5 mt-1 text-[10px] opacity-70 flex-wrap">
        <span dir="ltr">{{ m.at }}</span>
        <span v-if="m.source !== 'customer' && !m.sender">· {{ SOURCES[m.source] || m.source }}</span>
        <!-- حالة التسليم (يكملها E21) -->
        <slot name="status">
          <span v-if="m.status === 'failed'" class="font-bold" :title="m.error || ''">· ❌ فشل</span>
          <span v-else-if="m.status === 'blocked'" class="px-1.5 rounded bg-red-600 text-white font-bold" :title="m.error || ''">🛡️ حُجبت</span>
          <!-- دفتر الإرسال (E19): حُجز الصف قبل نداء ميتا، ولم يُحسم بعد -->
          <span v-else-if="m.status === 'queued'" data-test="status-queued">· ⏳ قيد الإرسال</span>
          <span v-else-if="m.status === 'unknown'" data-test="status-unknown" class="px-1.5 rounded bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200 font-bold"
                title="لا دليل على وصولها: تحقق مع العميل قبل الإعادة">⚠️ غير مؤكد — قد تكون وصلت</span>
        </slot>
      </div>
      <!-- سبب الفشل (E18): نص عربي من جدول الرموز، وفئة ملوّنة، وإجراء يُفتح بـ ⓘ. نص فقط بلا v-html -->
      <div v-if="hasReason" class="mt-1 space-y-1">
        <p class="text-[10px] leading-4 break-words" :class="m.category ? 'opacity-95' : 'opacity-80'">
          <span v-if="m.category" class="inline-block me-1 px-1.5 rounded font-bold" :class="chip" data-test="error-category">{{ CATEGORY_LABELS[m.category] || m.category }}</span>{{ m.error }}
        </p>
        <details v-if="m.action" class="text-[10px] leading-4" data-test="error-action">
          <summary class="cursor-pointer font-bold select-none">ⓘ ماذا أفعل؟</summary>
          <p class="mt-0.5 break-words">{{ m.action }}</p>
          <p v-if="m.fbtrace_id" class="mt-0.5 opacity-70">للدعم: <bdi dir="ltr" class="font-mono">{{ m.fbtrace_id }}</bdi></p>
        </details>
      </div>
      <!-- إعادة صريحة (E19): النص إلى المنشئ بمفتاح جديد، لا إرسال آلي. «غير مؤكد» يطلب تأكيداً قبل النسخ -->
      <button v-if="m.can_resend" type="button" data-test="copy-to-composer" @click="$emit('resend', m)"
              class="mt-1 min-h-8 px-2 rounded-lg text-[11px] font-bold bg-white/85 text-gray-800 dark:bg-gray-900 dark:text-gray-100 border border-black/10 dark:border-white/10">
        ✍️ انسخ إلى خانة الكتابة
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import WaText from './WaText.vue';

const props = defineProps({ m: { type: Object, required: true } });
defineEmits(['resend']);

const SOURCES = { bot: 'بوت', staff: 'موظف', system: 'نظام', injected: 'محقونة' };

// فئات MetaErrorCatalog (E18)
const CATEGORY_LABELS = {
  auth: 'اعتماد', integrity: 'تقييد', window: 'النافذة', throttle: 'خنق', transient: 'مؤقت',
  quality: 'جودة', caller: 'الطلب', unknown: 'غير معروف',
};

const hasReason = computed(() => ['failed', 'blocked', 'unknown'].includes(props.m.status) && !!props.m.error);

// الاعتماد والتقييد أحمر، والنافذة كهرماني، والطلب رمادي. خلفية فاتحة ثابتة لتُقرأ فوق أي لون فقاعة
const chip = computed(() => ({
  auth: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
  integrity: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
  window: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
  caller: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
}[props.m.category] || 'bg-white/80 text-gray-800 dark:bg-gray-900 dark:text-gray-200'));

// الوارد أبيض، والبوت أخضر، والنظام رمادي، والموظف ذهبي، والمحجوبة حمراء (E15)
const tone = computed(() => {
  const m = props.m;
  if (m.direction === 'in') return 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-ss-sm';
  if (m.status === 'blocked') return 'bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 border border-red-300 dark:border-red-700 rounded-se-sm';
  if (m.source === 'bot') return 'bg-emerald-600 text-white rounded-se-sm';
  if (m.source === 'system') return 'bg-gray-500 text-white rounded-se-sm';
  return 'bg-gold-500 text-black rounded-se-sm';
});
</script>
