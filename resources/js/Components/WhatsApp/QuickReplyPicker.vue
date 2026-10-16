<template>
  <!-- منتقي الردود الجاهزة (E17): بحث ومجموعات بالتصنيف؛ الاختيار يملأ المتغيرات في الخادم ثم يُدرج النص للمراجعة -->
  <AppModal :open="open" title="⚡ الردود الجاهزة" size="md" @close="$emit('close')">
    <label :for="searchId" class="sr-only">بحث في الردود</label>
    <input :id="searchId" v-model="query" data-autofocus type="search" dir="auto" placeholder="ابحث بالعنوان أو النص..."
           class="w-full mb-3 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm [unicode-bidi:plaintext]"/>
    <p v-if="loading" class="py-6 text-center text-sm text-gray-400">جارٍ التحميل...</p>
    <p v-else-if="error" class="py-4 text-center text-sm text-red-600" role="alert">❌ {{ error }}</p>
    <p v-else-if="!groups.length" class="py-6 text-center text-sm text-gray-400">{{ query ? 'لا نتائج' : 'لا ردود جاهزة مفعّلة بعد' }}</p>
    <div v-else class="space-y-3">
      <section v-for="g in groups" :key="g.key">
        <h3 class="text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-1">{{ g.label }}</h3>
        <ul class="space-y-1">
          <li v-for="r in g.items" :key="r.id">
            <button type="button" :disabled="busy" @click="pick(r)"
                    class="w-full text-start min-h-11 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-50">
              <span class="block text-sm font-bold">{{ r.title }}</span>
              <span dir="auto" class="block text-xs text-gray-500 dark:text-gray-400 line-clamp-2 [unicode-bidi:plaintext]">{{ r.body }}</span>
            </button>
          </li>
        </ul>
      </section>
    </div>
  </AppModal>
</template>

<script setup>
import { computed, ref, useId, watch } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import { groupReplies } from '@/composables/whatsapp/useQuickReplyRender.js';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  open: Boolean,
  conversationId: { type: Number, default: null },
});
const emit = defineEmits(['close', 'insert']);

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const searchId = `qr-search-${useId()}`;
const query = ref('');
const replies = ref([]);
const categories = ref({});
const loading = ref(false);
const busy = ref(false);
const error = ref('');
let loaded = false;

const groups = computed(() => groupReplies(replies.value, categories.value, query.value));

watch(() => props.open, async (v) => {
  if (!v) return;
  query.value = '';
  error.value = '';
  if (loaded) return;
  loading.value = true;
  try {
    const { data } = await axios.get('/api/whatsapp/quick-replies', api);
    replies.value = data.replies || [];
    categories.value = data.categories || {};
    loaded = true;
  } catch (e) {
    error.value = errorText(e, 'تعذّر تحميل الردود الجاهزة');
  } finally {
    loading.value = false;
  }
});

async function pick(r) {
  if (!props.conversationId || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const { data } = await axios.get(`/api/whatsapp/conversations/${props.conversationId}/quick-replies/render`, { ...api, params: { reply: r.id } });
    emit('insert', data.text, data.missing || []);
  } catch (e) {
    error.value = errorText(e, 'تعذّر ملء الرد');
  } finally {
    busy.value = false;
  }
}
</script>
