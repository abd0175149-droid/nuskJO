<template>
  <!-- منتقي تصنيفات المحادثة (E29): اختيار متعدد من القائمة الفعّالة وحدها بحد أقصى، بلا أي حقل كتابة -->
  <AppModal :open="open" title="🏷️ تصنيفات المحادثة" size="sm" @close="$emit('close')">
    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">داخلية لا يراها العميل، ولا تغيّر من يرى المحادثة. حتى {{ max }} تصنيفات.</p>
    <ul v-if="labels.length" class="space-y-1" role="group" aria-label="اختيار التصنيفات">
      <li v-for="l in labels" :key="l.id">
        <label class="flex items-center gap-2 min-h-11 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800"
               :class="disabled(l) ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'">
          <input type="checkbox" :checked="isOn(l)" :disabled="disabled(l)" @change="toggle(l, $event.target.checked)"
                 class="w-4 h-4 rounded text-gold-500" :data-test="'label-' + l.id"/>
          <span class="px-1.5 py-0.5 rounded text-xs ring-1" :class="labelClass(l.color)"><bdi dir="auto">{{ l.name }}</bdi></span>
        </label>
      </li>
    </ul>
    <p v-else class="py-4 text-center text-sm text-gray-400">لا تصنيفات مفعّلة بعد.</p>
    <p v-if="atMax" class="mt-2 text-xs text-amber-700 dark:text-amber-400" role="status">بلغت الحد ({{ max }}): أزل تصنيفاً لتضيف غيره.</p>
    <p v-if="error" class="mt-2 text-xs text-red-600" role="alert">❌ {{ error }}</p>
    <a v-if="canManage" href="/whatsapp/labels" class="mt-3 inline-block text-xs font-bold text-gold-700 dark:text-gold-400 underline">إدارة التصنيفات</a>
  </AppModal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import { errorText } from '@/composables/whatsapp/useInbox.js';
import { labelClass, setLabel } from '@/composables/whatsapp/useLabels.js';

const props = defineProps({
  open: Boolean,
  conversationId: { type: Number, default: null },
  // القائمة الفعّالة وحدها (من الخادم)
  labels: { type: Array, default: () => [] },
  // شرائح المحادثة الحالية (قد يكون فيها معطّل يُحسب في الحد)
  selected: { type: Array, default: () => [] },
  canManage: Boolean,
  max: { type: Number, default: 5 },
});
const emit = defineEmits(['changed', 'close']);

const busy = ref(false);
const error = ref('');
watch(() => props.open, (v) => { if (v) error.value = ''; });

const ids = computed(() => new Set(props.selected.map((l) => l.id)));
const atMax = computed(() => props.selected.length >= props.max);
const isOn = (l) => ids.value.has(l.id);
const disabled = (l) => busy.value || (!isOn(l) && atMax.value);

async function toggle(l, on) {
  if (!props.conversationId || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    emit('changed', await setLabel(props.conversationId, l.id, on));
  } catch (e) {
    error.value = errorText(e, 'تعذّر حفظ التصنيف');
  } finally {
    busy.value = false;
  }
}
</script>
