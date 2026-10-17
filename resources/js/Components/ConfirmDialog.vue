<template>
  <!-- v-for على طلب واحد: الفتحات تمسك طلبها حتى لو انتهى أثناء الإزالة -->
  <AppModal v-for="r in shown" :key="r.id" open :title="r.title" size="sm" :close-label="r.cancelLabel" @close="cancel(r)">
    <p v-if="r.message" dir="auto" class="text-sm leading-6 whitespace-pre-line [unicode-bidi:plaintext]">{{ r.message }}</p>
    <form v-if="r.kind === 'prompt'" @submit.prevent="ok(r)" class="space-y-1.5" :class="r.message ? 'mt-3' : ''">
      <label :for="inputId" class="block text-xs font-bold text-gray-600 dark:text-gray-300">{{ r.label }}<span v-if="r.required" class="text-red-600"> *</span></label>
      <textarea :id="inputId" v-model="text" data-autofocus rows="3" dir="auto" :maxlength="r.maxLength || undefined" :aria-invalid="tried && missing ? 'true' : undefined"
                @keydown.enter.ctrl.prevent="ok(r)" @keydown.enter.meta.prevent="ok(r)"
                class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm resize-y [unicode-bidi:plaintext]"></textarea>
      <p v-if="tried && missing" class="text-xs text-red-600" role="alert">هذا الحقل مطلوب.</p>
    </form>
    <template #footer>
      <!-- الإلغاء يأخذ التركيز في التأكيد، فـ Enter لا يعتمد خطأً -->
      <button type="button" @click="cancel(r)" :data-autofocus="r.kind === 'confirm' ? '' : undefined"
              class="min-h-11 px-4 rounded-xl text-sm font-bold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">{{ r.cancelLabel }}</button>
      <button type="button" @click="ok(r)" :disabled="tried && missing"
              class="min-h-11 px-4 rounded-xl text-sm font-bold disabled:opacity-50"
              :class="r.danger ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-gold-500 hover:bg-gold-400 text-black'">{{ r.confirmLabel }}</button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import AppModal from './AppModal.vue';
import { confirmState, registerHost, settle } from '@/composables/useConfirm.js';

const host = registerHost();
onBeforeUnmount(() => host.release());

// مضيفان معاً (نادر): الأول وحده يعرض
const active = computed(() => confirmState.hosts[0] === host.id);
const req = computed(() => confirmState.current);
const shown = computed(() => (active.value && req.value ? [req.value] : []));
const inputId = `confirm-input-${useId()}`;
const text = ref('');
const tried = ref(false);
const missing = computed(() => !!req.value?.required && !text.value.trim());

watch(req, (r) => { text.value = r?.value || ''; tried.value = false; }, { immediate: true });

// كل نافذة تُنهي طلبها هي وحده، لا طلباً لاحقاً حلّ محلّه
function ok(r) {
  if (!r || req.value !== r) return;
  if (r.kind === 'prompt') {
    tried.value = true;
    if (missing.value) return;
    settle(text.value.trim());
  } else settle(true);
}

function cancel(r) {
  if (r && req.value === r) settle(r.kind === 'prompt' ? null : false);
}
</script>
