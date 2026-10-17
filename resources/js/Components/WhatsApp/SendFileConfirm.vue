<template>
  <!-- تأكيد المرفق (E32.10): لا يُرسل أي ملف بلا هذا الحوار. يُظهر الملف وحجمه والمستلم بالاسم وآخر 4 أرقام، ونوع الوثيقة،
       وتأكيداً ثانياً للتأشيرة (تحمل رقم الجواز). الخادم يطابق المحادثة المؤكَّدة من جديد -->
  <AppModal :open="open" title="تأكيد إرسال ملف" @close="$emit('close')">
    <div class="space-y-3 text-sm">
      <div class="flex items-center gap-3 min-w-0">
        <img v-if="previewUrl" :src="previewUrl" alt="معاينة الصورة" class="w-16 h-16 shrink-0 rounded-lg object-cover border border-gray-200 dark:border-gray-700"/>
        <span v-else class="text-3xl shrink-0" aria-hidden="true">{{ KIND_ICONS[kind] || '📎' }}</span>
        <div class="min-w-0 space-y-0.5">
          <p data-test="file-line">سترسل: <bdi dir="auto" class="font-bold break-all">{{ file?.name }}</bdi> <span dir="ltr" class="text-xs text-gray-500 dark:text-gray-400">({{ formatSize(file?.size) }})</span></p>
          <p data-test="recipient-line">إلى: <bdi dir="auto" class="font-bold">{{ recipient?.name }}</bdi><template v-if="recipient?.last4"> • <bdi dir="ltr">…{{ recipient.last4 }}</bdi></template><template v-if="recipient?.clientCode"> • <bdi dir="ltr">{{ recipient.clientCode }}</bdi></template></p>
        </div>
      </div>

      <label class="block">
        <span class="block mb-1 text-xs font-bold text-gray-600 dark:text-gray-300">نوع الوثيقة</span>
        <select v-model="docKind" :data-autofocus="needsKind ? '' : null" aria-label="نوع الوثيقة"
                class="w-full min-h-11 px-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white">
          <option value="" disabled>اختر نوع الوثيقة</option>
          <option v-for="(label, key) in docKinds" :key="key" :value="key">{{ label }}</option>
        </select>
      </label>

      <p v-if="kind === 'document'" data-test="document-warning" class="rounded-lg px-3 py-2 bg-amber-50 dark:bg-amber-900/30 text-amber-900 dark:text-amber-200 text-xs">
        ⚠️ تأكد أن الوثيقة تخص هذا العميل؛ الوثائق قد تحوي بيانات جواز.
      </p>
      <label v-if="docKind === 'visa'" data-test="visa-confirm" class="flex items-start gap-2 rounded-lg px-3 py-2 border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 text-xs text-red-900 dark:text-red-200">
        <input v-model="confirmIdentity" type="checkbox" class="mt-0.5 w-4 h-4 shrink-0"/>
        <span>تحققتُ أن هذه التأشيرة تخص <bdi dir="auto" class="font-bold">{{ recipient?.name }}</bdi> نفسه، فهي تحمل رقم جوازه.</span>
      </label>

      <label class="block">
        <span class="block mb-1 text-xs font-bold text-gray-600 dark:text-gray-300">تعليق (اختياري)</span>
        <textarea v-model="caption" :maxlength="maxCaption" rows="2" dir="auto" aria-label="تعليق الملف"
                  class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white resize-none [unicode-bidi:plaintext]"></textarea>
        <span class="block text-[11px] text-gray-400 text-end"><bdi dir="ltr">{{ caption.length }}/{{ maxCaption }}</bdi></span>
      </label>
    </div>

    <template #footer>
      <button type="button" @click="$emit('close')"
              class="min-h-11 px-4 rounded-xl font-bold text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">إلغاء</button>
      <button type="button" :data-autofocus="needsKind ? null : ''" :disabled="!canSend" @click="confirm" data-test="send-file"
              class="min-h-11 px-5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">📤 إرسال</button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import { KIND_ICONS, formatSize } from '@/utils/media.js';

const props = defineProps({
  open: Boolean,
  file: { type: Object, default: null },
  kind: { type: String, default: 'document' },
  // {name, last4, clientCode}: لحظة فتح الحوار، فلا يتغير المستلم بتبديل المحادثة
  recipient: { type: Object, default: () => ({}) },
  docKinds: { type: Object, default: () => ({}) },
  maxCaption: { type: Number, default: 1024 },
});
const emit = defineEmits(['close', 'confirm']);

const caption = ref('');
const docKind = ref('');
const confirmIdentity = ref(false);
const previewUrl = ref(null);

// المستند يختار نوعه صراحة؛ الصورة والفيديو «أخرى» ما لم يُغيَّر
const needsKind = computed(() => props.kind === 'document');
const canSend = computed(() => !!props.file && !!docKind.value && (docKind.value !== 'visa' || confirmIdentity.value)
  && caption.value.length <= props.maxCaption);

function revoke() {
  if (previewUrl.value) {
    try { URL.revokeObjectURL(previewUrl.value); } catch { /* لا شيء */ }
  }
  previewUrl.value = null;
}

watch(() => [props.open, props.file], ([open, file]) => {
  revoke();
  if (!open) return;
  caption.value = '';
  docKind.value = needsKind.value ? '' : 'other';
  confirmIdentity.value = false;
  if (file && props.kind === 'image') {
    try { previewUrl.value = URL.createObjectURL(file); } catch { previewUrl.value = null; }
  }
}, { immediate: true });

onBeforeUnmount(revoke);

function confirm() {
  if (!canSend.value) return;
  emit('confirm', { caption: caption.value.trim(), docKind: docKind.value, confirmIdentity: confirmIdentity.value });
}
</script>
