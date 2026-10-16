<template>
  <span class="inline-flex flex-wrap items-center gap-1.5">
    <button type="button" data-test="call-from-agency" @click="open = !open" :aria-expanded="open ? 'true' : 'false'"
            title="المعاودة من هاتف الوكالة العادي: يُعرض رقم العميل للنسخ"
            class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-sky-300 text-sky-800 dark:border-sky-700 dark:text-sky-200">
      📞 اتصل من هاتف الوكالة
    </button>
    <span v-if="open" data-test="call-number" class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg bg-sky-50 dark:bg-sky-900/30 text-xs">
      <bdi dir="ltr" class="font-mono select-all">{{ shown }}</bdi>
      <button type="button" data-test="copy-number" @click="copy" class="min-h-11 lg:min-h-0 px-2 rounded font-bold text-sky-800 dark:text-sky-200 hover:underline">
        {{ copied ? '✅ نُسخ' : '📋 نسخ' }}
      </button>
    </span>
    <span v-if="failed" role="status" class="text-[11px] text-amber-700 dark:text-amber-300">حدّد الرقم وانسخه يدوياً</span>
  </span>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { formatPhone } from '@/composables/whatsapp/usePhoneFormat.js';

// المعاودة من الهاتف العادي (E39): لا مكالمة يبدؤها النظام (تحتاج عرض SDP)، فالزر يعرض رقم العميل للنسخ فقط
const props = defineProps({ phone: { type: String, required: true } });

const open = ref(false);
const copied = ref(false);
const failed = ref(false);
const shown = computed(() => formatPhone(props.phone));
watch(() => props.phone, () => { open.value = false; copied.value = false; failed.value = false; });

const copy = async () => {
  failed.value = false;
  try {
    await navigator.clipboard.writeText('+' + String(props.phone).replace(/\D/g, ''));
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  } catch (e) {
    failed.value = true;
  }
};
</script>
