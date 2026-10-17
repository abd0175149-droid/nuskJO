<template>
  <!-- رمز QR جديد (E38): القناة ووصف داخلي لا يُرسل. الرسالة المعبأة يبنيها الخادم من عنوان العرض والمرجع وحدهما -->
  <AppModal :open="open" title="➕ رمز QR ورابط قصير جديد" :dirty="!!label.trim()" size="md" @close="close">
    <form :id="formId" class="space-y-3" @submit.prevent="submit">
      <fieldset>
        <legend class="text-xs font-bold text-gray-600 dark:text-gray-300 mb-1.5">أين سيُستعمل؟</legend>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
          <label v-for="(text, key) in channels" :key="key" class="flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer text-sm"
                 :class="channel === key ? 'border-gold-400 bg-gold-50 dark:bg-gold-900/20' : 'border-gray-200 dark:border-gray-700'">
            <input v-model="channel" type="radio" name="qr-channel" :value="key" class="w-4 h-4 shrink-0"/>
            <span>{{ text }}</span>
          </label>
        </div>
      </fieldset>
      <div>
        <label :for="`${formId}-label`" class="block text-xs font-bold text-gray-600 dark:text-gray-300 mb-1">وصف داخلي (اختياري)</label>
        <input :id="`${formId}-label`" v-model="label" maxlength="80" dir="auto" placeholder="مثلاً: ملصق معرض رمضان"
               class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white"/>
        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">لا يصل العميل ولا ميتا. الرسالة المعبأة تحمل عنوان العرض ومرجعه فقط.</p>
      </div>
      <p v-if="error" role="alert" class="text-xs text-red-600 dark:text-red-400">❌ {{ error }}</p>
    </form>
    <template #footer>
      <button type="button" class="min-h-11 px-4 py-2 rounded-xl text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200" @click="close">إلغاء</button>
      <button type="submit" :form="formId" :disabled="!channel || saving"
              class="min-h-11 px-5 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
        {{ saving ? '...' : 'إنشاء' }}
      </button>
    </template>
  </AppModal>
</template>

<script setup>
import { ref, useId, watch } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  open: Boolean,
  endpoint: { type: String, required: true },
  channels: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'created', 'failed']);

const formId = `qr-form-${useId()}`;
const channel = ref('');
const label = ref('');
const saving = ref(false);
const error = ref('');

watch(() => props.open, (v) => {
  if (!v) return;
  channel.value = Object.keys(props.channels)[0] || '';
  label.value = '';
  error.value = '';
});

const close = () => {
  if (!saving.value) emit('close');
};

async function submit() {
  if (!channel.value || saving.value) return;
  saving.value = true;
  error.value = '';
  try {
    const { data } = await axios.post(props.endpoint, { channel: channel.value, label: label.value.trim() || null },
      { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    emit('created', data);
  } catch (e) {
    error.value = errorText(e, 'تعذّر إنشاء الرمز');
    // صف «فشل» قد حُفظ: القائمة تُحدَّث
    emit('failed');
  } finally {
    saving.value = false;
  }
}
</script>
