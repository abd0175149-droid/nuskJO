<template>
  <!-- الملاحظة المثبتة وبطاقة الحقائق (E29): داخلية لا يراها العميل ولا البوت. تُحمَّل عند فتح المحادثة وبعد كل تعديل، لا مع الاستطلاع -->
  <section class="shrink-0 border-b border-amber-200 dark:border-amber-900/50 bg-amber-50/70 dark:bg-amber-900/10" aria-label="الملاحظة المثبتة وحقائق العميل" data-test="fact-card">
    <button type="button" @click="toggleCollapsed" :aria-expanded="collapsed ? 'false' : 'true'"
            class="w-full min-h-9 px-3 sm:px-4 py-1.5 flex items-center gap-1.5 text-start text-[11px]">
      <span aria-hidden="true">📌</span>
      <span class="font-bold text-amber-900 dark:text-amber-200 shrink-0">ملاحظة مثبتة</span>
      <span class="text-amber-800 dark:text-amber-300 shrink-0">— 🔒 داخلي، لا يراه العميل</span>
      <bdi v-if="collapsed && summary" dir="auto" class="min-w-0 truncate text-gray-600 dark:text-gray-300 [unicode-bidi:plaintext]">· {{ summary }}</bdi>
      <span class="ms-auto shrink-0 text-gray-500" aria-hidden="true">{{ collapsed ? '▾' : '▴' }}</span>
    </button>

    <div v-if="!collapsed" class="px-3 sm:px-4 pb-2 space-y-2 max-h-60 overflow-y-auto">
      <p v-if="loading && !facts" class="text-xs text-gray-500">جارٍ التحميل...</p>
      <p v-else-if="loadError" class="text-xs text-red-600" role="alert">❌ {{ loadError }}</p>

      <template v-if="facts">
        <!-- الملاحظة -->
        <div v-if="!editing">
          <p v-if="note" dir="auto" class="text-sm text-gray-800 dark:text-gray-100 break-words [unicode-bidi:plaintext]" data-test="pinned-note">{{ note.text }}</p>
          <p v-else class="text-xs text-gray-500 dark:text-gray-400">لا ملاحظة مثبتة.</p>
          <p v-if="note" class="text-[10px] text-gray-500 dark:text-gray-400">عدّلها {{ note.by || '—' }} · <bdi dir="ltr">{{ note.at }}</bdi></p>
          <div v-if="canReply" class="flex flex-wrap gap-2 mt-1">
            <button type="button" @click="startEdit" class="min-h-8 px-2.5 rounded-lg text-[11px] font-bold border border-amber-300 dark:border-amber-700 text-amber-900 dark:text-amber-200">
              {{ note ? '✏️ تعديل الملاحظة' : '+ ملاحظة مثبتة' }}
            </button>
            <button v-if="note" type="button" :disabled="saving" @click="clearNote" class="min-h-8 px-2.5 rounded-lg text-[11px] text-red-700 dark:text-red-300 disabled:opacity-50">🗑️ إزالة</button>
          </div>
        </div>
        <form v-else class="space-y-1" @submit.prevent="saveNote">
          <label :for="noteId" class="sr-only">الملاحظة المثبتة</label>
          <textarea :id="noteId" v-model="draft" rows="2" :maxlength="MAX" dir="auto" data-autofocus
                    class="w-full px-3 py-2 rounded-xl border border-amber-300 dark:border-amber-700 bg-white dark:bg-gray-900 text-sm dark:text-white resize-none [unicode-bidi:plaintext]"></textarea>
          <div class="flex flex-wrap items-center gap-2">
            <span dir="ltr" class="text-[10px] font-mono text-gray-500" data-test="note-counter">{{ draft.length }} / {{ MAX }}</span>
            <button type="submit" :disabled="saving || !draft.trim()" class="min-h-8 px-3 rounded-lg text-[11px] font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
              {{ saving ? '...' : 'حفظ' }}
            </button>
            <button type="button" @click="editing = false" class="min-h-8 px-2.5 rounded-lg text-[11px] text-gray-600 dark:text-gray-300">إلغاء</button>
          </div>
          <p class="text-[10px] text-gray-500 dark:text-gray-400">لا تكتب أرقام جوازات أو هويات أو بطاقات هنا.</p>
        </form>
        <p v-if="noteError" class="text-xs font-bold text-red-600 dark:text-red-400" role="alert" data-test="note-error">{{ noteError }}</p>

        <!-- الرسائل المثبتة -->
        <ul v-if="pinnedMessages.length" class="space-y-1" aria-label="الرسائل المثبتة">
          <li v-for="p in pinnedMessages" :key="p.id" class="flex items-start gap-1.5">
            <button type="button" @click="$emit('jump', p.id)" class="flex-1 min-w-0 text-start text-xs text-gray-700 dark:text-gray-200 hover:underline">
              <span aria-hidden="true">{{ p.direction === 'in' ? '👤' : '↩️' }}</span>
              <bdi dir="auto" class="[unicode-bidi:plaintext]">{{ p.excerpt }}</bdi>
            </button>
            <button v-if="canReply" type="button" :disabled="saving" @click="unpin(p.id)" aria-label="إلغاء تثبيت الرسالة" title="إلغاء تثبيت الرسالة"
                    class="shrink-0 min-w-8 min-h-8 rounded-lg text-xs text-gray-500 hover:text-red-600 disabled:opacity-40">✕</button>
          </li>
        </ul>

        <!-- حقائق من النظام: كل تفصيل بصلاحيته في الخادم -->
        <div v-if="line.length || facts.hint" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-gray-700 dark:text-gray-300" data-test="facts-line">
          <template v-for="(f, i) in line" :key="f.key">
            <span v-if="i" aria-hidden="true" class="text-gray-400">·</span>
            <a v-if="f.url" :href="f.url" class="hover:underline"><bdi dir="auto">{{ f.text }}</bdi></a>
            <bdi v-else dir="auto">{{ f.text }}</bdi>
          </template>
          <span v-if="facts.hint" class="text-gray-500 dark:text-gray-400">{{ facts.hint }}</span>
        </div>
      </template>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue';
import axios from 'axios';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  conversationId: { type: Number, required: true },
  canReply: Boolean,
});
const emit = defineEmits(['jump', 'pins']);

const MAX = 500;
const STORE = 'wa:factcard:collapsed';
const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const url = (path) => `/api/whatsapp/conversations/${props.conversationId}/${path}`;
const noteId = `pin-note-${useId()}`;

const facts = ref(null);
const loading = ref(false);
const loadError = ref('');
const editing = ref(false);
const draft = ref('');
const saving = ref(false);
const noteError = ref('');
let seq = 0;

// الطي تفضيل للمتصفح وحده
const readCollapsed = () => {
  try { return localStorage.getItem(STORE) === '1'; } catch { return false; }
};
const collapsed = ref(readCollapsed());
function toggleCollapsed() {
  collapsed.value = !collapsed.value;
  try { localStorage.setItem(STORE, collapsed.value ? '1' : '0'); } catch { /* التخزين غير متاح */ }
}

const note = computed(() => facts.value?.pinned?.note || null);
const pinnedMessages = computed(() => facts.value?.pinned?.messages || []);
const summary = computed(() => note.value?.text || (pinnedMessages.value.length ? `${pinnedMessages.value.length} رسائل مثبتة` : ''));

const line = computed(() => {
  const f = facts.value || {};
  const out = [];
  if (f.client) out.push({ key: 'client', text: `👤 ${f.client.name}${f.client.code ? ` (${f.client.code})` : ''}`, url: f.client.url });
  if (f.next_trip) out.push({ key: 'trip', text: `✈️ السفر ${f.next_trip.date}${f.next_trip.invoice_number ? ` · فاتورة ${f.next_trip.invoice_number}` : ''}`, url: f.next_trip.url });
  if (f.open_quote) out.push({ key: 'quote', text: `💰 طلب تسعير ${f.open_quote.request_number} (${f.open_quote.status_label})`, url: f.open_quote.url });
  if (f.last_invoice) out.push({ key: 'invoice', text: `🧾 آخر فاتورة ${f.last_invoice.number} (${f.last_invoice.status_label})`, url: f.last_invoice.url });
  if (f.balance) out.push({ key: 'balance', text: `💳 الرصيد: ${f.balance.direction ? `${f.balance.direction} ` : ''}${f.balance.amount} ${f.balance.currency}` });
  return out;
});

function apply(pinned) {
  if (!facts.value) facts.value = {};
  facts.value = { ...facts.value, pinned };
  emit('pins', (pinned?.messages || []).map((m) => m.id));
}

async function reload() {
  const mine = ++seq;
  loading.value = true;
  loadError.value = '';
  try {
    const { data } = await axios.get(url('facts'), api);
    if (mine !== seq) return;
    facts.value = data;
    emit('pins', (data.pinned?.messages || []).map((m) => m.id));
  } catch (e) {
    if (mine === seq) loadError.value = errorText(e, 'تعذّر تحميل الملاحظة المثبتة');
  } finally {
    if (mine === seq) loading.value = false;
  }
}

function startEdit() {
  draft.value = note.value?.text || '';
  noteError.value = '';
  editing.value = true;
}

async function write(method, path, payload) {
  saving.value = true;
  noteError.value = '';
  try {
    const { data } = await axios[method](url(path), ...(method === 'delete' ? [api] : [payload, api]));
    apply(data.pinned);
    return true;
  } catch (e) {
    noteError.value = errorText(e, 'تعذّر الحفظ');
    return false;
  } finally {
    saving.value = false;
  }
}

async function saveNote() {
  if (await write('put', 'pin-note', { note: draft.value })) editing.value = false;
}
const clearNote = () => write('delete', 'pin-note');
const unpin = (id) => write('delete', `messages/${id}/pin`);

watch(() => props.conversationId, () => {
  facts.value = null;
  editing.value = false;
  noteError.value = '';
  reload();
});
onMounted(reload);

defineExpose({ reload, setPinned: apply });
</script>
