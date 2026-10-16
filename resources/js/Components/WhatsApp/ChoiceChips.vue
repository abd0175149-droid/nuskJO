<template>
  <!-- الاختيارات التفاعلية (E36): ما عُرض على العميل حبوباً رمادية غير قابلة للنقر (وشارة «منتهية»)، وما اختاره بشارة خضراء
       أو كهرمانية بسببها، وردّ نموذج طلب التسعير برابط الطلب. نص فقط بلا v-html، والمعرّفات لا تصل الواجهة أصلاً -->
  <div v-if="hasContent" class="mt-1 space-y-1" data-test="choice-chips">
    <div v-if="m.choices" class="space-y-1" data-test="choices-shown">
      <p v-if="m.choices.header" dir="auto" class="text-[11px] font-bold opacity-90 break-words [unicode-bidi:plaintext]">{{ m.choices.header }}</p>
      <ul class="flex flex-wrap gap-1" :aria-label="TYPE_LABELS[m.choices.type] || 'خيارات'">
        <li v-for="(item, i) in m.choices.items" :key="i" dir="auto" :title="item.description || null"
            class="max-w-full px-2 py-0.5 rounded-full text-[11px] leading-5 break-words [unicode-bidi:plaintext] bg-white/85 text-gray-700 dark:bg-gray-900 dark:text-gray-200 border border-black/10 dark:border-white/10"
            data-test="choice-item">
          <span>{{ m.choices.type === 'flow' ? '📝 ' : '' }}{{ item.title }}</span>
          <span v-if="item.description" class="opacity-70"> · {{ item.description }}</span>
        </li>
      </ul>
      <p class="text-[10px] font-bold flex items-center gap-1">
        <span class="opacity-80">{{ TYPE_LABELS[m.choices.type] || 'خيارات' }}</span>
        <span v-if="m.choices.expired" class="px-1.5 rounded bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-300" data-test="choices-expired">منتهية</span>
      </p>
    </div>

    <p v-if="m.chosen" dir="auto" data-test="choice-chosen"
       class="inline-flex flex-wrap items-center gap-1 max-w-full px-2 py-0.5 rounded-lg text-[11px] font-bold break-words [unicode-bidi:plaintext]"
       :class="m.chosen.valid
         ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
         : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'">
      <span>🔘 اختيار: {{ m.chosen.label }}</span>
      <span v-if="!m.chosen.valid && m.chosen.reason_label" class="font-normal" data-test="choice-reason">— {{ m.chosen.reason_label }}</span>
    </p>

    <div v-if="m.quote_form" data-test="quote-form"
         class="rounded-lg border px-2.5 py-1.5 text-[11px]"
         :class="m.quote_form.valid
           ? 'border-black/10 dark:border-white/10 bg-white/70 dark:bg-gray-900/60 text-gray-800 dark:text-gray-100'
           : 'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/60 text-amber-900 dark:text-amber-100'">
      <p class="font-bold">📝 نموذج طلب تسعير</p>
      <template v-if="m.quote_form.valid">
        <a v-if="m.quote_form.url" :href="m.quote_form.url" class="inline-flex items-center min-h-8 font-bold text-gold-700 dark:text-gold-400 underline"
           data-test="quote-form-link">فتح الطلب {{ m.quote_form.request_number }}</a>
        <p v-else-if="m.quote_form.request_number">الطلب {{ m.quote_form.request_number }}</p>
        <p v-if="m.quote_form.duplicate" class="opacity-75">ردّ مكرر على النموذج نفسه — لم يُنشأ طلب ثانٍ.</p>
      </template>
      <p v-else data-test="quote-form-invalid">لا يطابق نموذجاً أرسلناه لهذه المحادثة — لم يُنشأ طلب.</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  m: { type: Object, required: true },
});

const TYPE_LABELS = { list: 'قائمة أُرسلت للعميل', button: 'أزرار أُرسلت للعميل', flow: 'نموذج أُرسل للعميل' };

const hasContent = computed(() => !!(props.m.choices?.items?.length || props.m.chosen || props.m.quote_form));
</script>
