<template>
  <AppLayout>
    <template #header>المحظورون على واتساب</template>

    <!-- الحظر الرسمي (E31): القائم والسجل، والمطابقة القرائية مع قائمة ميتا. كل إصلاح بزر وسبب مكتوب -->
    <div class="space-y-4 max-w-5xl">
      <p class="text-sm text-gray-600 dark:text-gray-300">
        الحظر الرسمي عند واتساب: المحظور لا يستطيع مراسلتنا ولا نستطيع مراسلته. يُحظر من الصندوق خلال 24 ساعة من آخر رسالة للعميل (شرط ميتا)، ويُفك من هنا أو من المحادثة بسبب مكتوب.
      </p>

      <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 space-y-2" data-test="sync-card">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-bold text-gray-800 dark:text-gray-100">🔄 المطابقة مع قائمة ميتا</h2>
          <button type="button" data-test="sync-btn" :disabled="syncing || !channelReady" @click="sync"
                  class="min-h-11 px-4 py-2 rounded-xl text-sm font-bold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50">
            {{ syncing ? 'جارٍ القراءة…' : 'مزامنة الآن' }}
          </button>
        </div>
        <p v-if="!channelReady" class="text-xs text-amber-700 dark:text-amber-400">القناة غير مهيأة: المزامنة والحظر معطّلان حتى تكتمل إعدادات واتساب.</p>
        <p v-if="summary" class="text-xs text-gray-600 dark:text-gray-300" data-test="sync-summary">
          آخر مزامنة <bdi dir="ltr">{{ when(summary.at) }}</bdi> ({{ summary.trigger === 'manual' ? 'يدوية' : 'تلقائية' }}):
          عند ميتا {{ summary.meta }}، والقائم عندنا {{ summary.local }}.
          <template v-if="summary.not_found"> {{ summary.not_found }} غير موجود عند ميتا.</template>
          <template v-if="summary.untracked"> {{ summary.untracked }} عند ميتا بلا سجل عندنا.</template>
          <template v-if="summary.mismatch"> {{ summary.mismatch }} وصلت منه رسالة.</template>
        </p>
        <p v-else class="text-xs text-gray-500 dark:text-gray-400">لم تُشغَّل المزامنة بعد.</p>
        <p v-if="summary?.truncated" class="text-xs font-bold text-amber-700 dark:text-amber-400">⚠️ قائمة ميتا أطول من حد القراءة: النتيجة جزئية، ولا يُحكم بغياب أي حظر.</p>
        <p class="text-[11px] text-gray-500 dark:text-gray-400">المزامنة قراءة ومقارنة فقط: لا تغيّر أي حظر عند ميتا، وكل إصلاح بزر منك.</p>
        <p v-if="error" role="alert" class="text-xs text-red-600 dark:text-red-400">❌ {{ error }}</p>
        <p v-if="notice" role="status" class="text-xs text-green-700 dark:text-green-400">✅ {{ notice }}</p>
      </section>

      <!-- نتيجة آخر مزامنة يدوية: ما يحتاج قراراً -->
      <section v-if="result" class="space-y-3" data-test="sync-result">
        <p v-if="!result.not_found.length && !result.untracked.length && !result.mismatch.length"
           class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 px-3 py-2 text-sm text-green-800 dark:text-green-200">
          ✅ قائمتنا تطابق قائمة ميتا.
        </p>

        <div v-if="result.not_found.length" class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-3 space-y-2" data-test="not-found">
          <h3 class="text-sm font-bold text-amber-900 dark:text-amber-200">⚠️ محظور عندنا وغير موجود عند ميتا ({{ result.not_found.length }})</h3>
          <p class="text-xs text-amber-900 dark:text-amber-200">ربما فُكّ الحظر من تطبيق واتساب نفسه. أعد الحظر (والنافذة مفتوحة) أو أنهِ السجل عندنا من القائمة أدناه.</p>
          <ul class="space-y-1">
            <li v-for="r in result.not_found" :key="`nf-${r.id}`" class="flex flex-wrap items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
              <bdi dir="auto" class="[unicode-bidi:plaintext] break-words min-w-0">{{ r.name || 'بلا محادثة' }}</bdi>
              <bdi v-if="r.last4" dir="ltr" class="font-mono text-xs text-gray-500 dark:text-gray-400">…{{ r.last4 }}</bdi>
            </li>
          </ul>
        </div>

        <div v-if="result.untracked.length" class="rounded-xl border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-900/20 p-3 space-y-2" data-test="untracked">
          <h3 class="text-sm font-bold text-sky-900 dark:text-sky-200">🔎 محظور عند ميتا بلا سجل عندنا ({{ result.untracked.length }})</h3>
          <p class="text-xs text-sky-900 dark:text-sky-200">حُظر من خارج النظام (من تطبيق واتساب مثلاً). سجّل سببه ليبقى عندنا، أو فكّه.</p>
          <ul class="space-y-2">
            <li v-for="u in result.untracked" :key="u.ref" class="flex flex-wrap items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
              <span class="min-w-0 break-words">
                <bdi dir="auto" class="[unicode-bidi:plaintext]">{{ u.name || (u.kind === 'bsuid' ? '📵 بالمعرّف' : 'بلا محادثة عندنا') }}</bdi>
                <bdi v-if="u.last4" dir="ltr" class="font-mono text-xs text-gray-500 dark:text-gray-400 ms-1">…{{ u.last4 }}</bdi>
              </span>
              <span class="flex flex-wrap gap-1.5 ms-auto">
                <button type="button" :disabled="busy" @click="openRegister(u)"
                        class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-sky-300 dark:border-sky-700 text-sky-800 dark:text-sky-200 disabled:opacity-50">تسجيل سبب</button>
                <button type="button" :disabled="busy" @click="unblockUntracked(u)"
                        class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 disabled:opacity-50">فك الحظر</button>
              </span>
            </li>
          </ul>
        </div>

        <div v-if="result.mismatch.length" class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3 space-y-2" data-test="mismatch">
          <h3 class="text-sm font-bold text-red-800 dark:text-red-200">✉️ وصلت رسالة من محظور قبل هذه المزامنة ({{ result.mismatch.length }})</h3>
          <ul class="space-y-1">
            <li v-for="r in result.mismatch" :key="`mm-${r.id}`" class="flex flex-wrap items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
              <bdi dir="auto" class="[unicode-bidi:plaintext] break-words min-w-0">{{ r.name || 'بلا محادثة' }}</bdi>
              <bdi v-if="r.last4" dir="ltr" class="font-mono text-xs text-gray-500 dark:text-gray-400">…{{ r.last4 }}</bdi>
              <span class="text-xs">{{ r.now === 'blocked' ? '— الحظر قائم عند ميتا الآن' : '— غير موجود عند ميتا' }}</span>
            </li>
          </ul>
        </div>
      </section>

      <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700" role="tablist" aria-label="الحظر">
        <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" @click="tab = t.key"
                class="min-h-11 px-4 py-2 -mb-px border-b-2 text-sm font-bold"
                :class="tab === t.key ? 'border-red-600 text-red-700 dark:text-red-300' : 'border-transparent text-gray-500 dark:text-gray-400'">
          {{ t.label }} ({{ (t.key === 'active' ? active : history).length }})
        </button>
      </div>

      <ul v-if="rows.length" class="space-y-2" :data-test="`rows-${tab}`">
        <li v-for="b in rows" :key="b.id" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 text-sm space-y-1.5">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="font-bold text-gray-800 dark:text-gray-100 min-w-0 break-words">
              <bdi dir="auto" class="[unicode-bidi:plaintext]">{{ b.name || KIND[b.kind] }}</bdi>
              <bdi v-if="b.last4" dir="ltr" class="font-mono text-xs font-normal text-gray-500 dark:text-gray-400 ms-1">…{{ b.last4 }}</bdi>
              <span v-if="b.kind === 'bsuid'" class="ms-1 text-[11px] font-normal text-gray-500 dark:text-gray-400">📵 بالمعرّف</span>
            </p>
            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold shrink-0" :class="(STATE[b.meta_state] || STATE.blocked).cls">
              {{ (STATE[b.meta_state] || STATE.blocked).label }}
            </span>
          </div>
          <p class="text-xs text-gray-700 dark:text-gray-200">
            <span class="font-bold">{{ b.reason_label }}</span><template v-if="b.reason">: <bdi dir="auto" class="[unicode-bidi:plaintext] break-words">{{ b.reason }}</bdi></template>
          </p>
          <p class="text-[11px] text-gray-500 dark:text-gray-400">
            حظره {{ b.by || 'غير معروف' }} {{ VIA[b.via] || '' }} — <bdi dir="ltr">{{ when(b.blocked_at) }}</bdi>
            <template v-if="b.meta_checked_at"> — آخر فحص عند ميتا <bdi dir="ltr">{{ when(b.meta_checked_at) }}</bdi></template>
            <template v-if="b.meta_error_code"> — آخر خطأ من ميتا: <bdi dir="ltr" class="font-mono">{{ b.meta_error_code }}</bdi></template>
          </p>
          <p v-if="b.unblocked_at" class="text-[11px] text-gray-500 dark:text-gray-400">
            فُكّ <bdi dir="ltr">{{ when(b.unblocked_at) }}</bdi><template v-if="b.unblocked_by"> بواسطة {{ b.unblocked_by }}</template><template v-if="b.unblock_reason"> — <bdi dir="auto" class="[unicode-bidi:plaintext] break-words">{{ b.unblock_reason }}</bdi></template>
          </p>
          <div v-if="tab === 'active'" class="flex flex-wrap gap-1.5 pt-1">
            <Link v-if="b.conversation_id" :href="`/whatsapp/inbox?conv=${b.conversation_id}`"
                  class="min-h-11 lg:min-h-0 inline-flex items-center px-2.5 py-1 rounded-lg text-xs border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200">فتح المحادثة</Link>
            <button v-if="(b.meta_state === 'not_found' || b.meta_state === 'mismatch') && b.conversation_id" type="button"
                    :disabled="busy || !b.window_open" @click="reblock(b)"
                    :title="b.window_open ? 'إعادة الحظر عند ميتا' : 'ميتا لا تسمح بالحظر إلا خلال 24 ساعة من آخر رسالة للعميل'"
                    class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 disabled:opacity-40">⛔ إعادة الحظر عند ميتا</button>
            <button type="button" :disabled="busy" @click="unblock(b)" :data-test="`unblock-${b.id}`"
                    class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 disabled:opacity-50">
              {{ b.meta_state === 'not_found' ? 'إنهاء السجل عندنا' : 'فك الحظر' }}
            </button>
          </div>
        </li>
      </ul>
      <p v-else class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 px-3 py-10 text-center text-sm text-gray-400">
        {{ tab === 'active' ? 'لا حظر قائم.' : 'لا سجل بعد.' }}
      </p>
    </div>

    <AppModal :open="!!registering" title="تسجيل سبب حظر قائم عند ميتا" :dirty="!!(regCode || regReason.trim())" size="md" @close="registering = null">
      <form id="register-untracked" class="space-y-3" @submit.prevent="register">
        <p class="text-xs text-gray-600 dark:text-gray-300">
          لا نداء لميتا هنا: الحظر قائم عندها، ونسجّل سببه عندنا فقط.
          <bdi v-if="registering?.last4" dir="ltr" class="font-mono">…{{ registering.last4 }}</bdi>
        </p>
        <fieldset>
          <legend class="text-xs font-bold text-gray-600 dark:text-gray-300 mb-1.5">سبب الحظر</legend>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
            <label v-for="(label, key) in reasons" :key="key"
                   class="flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer text-sm"
                   :class="regCode === key ? 'border-red-400 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-gray-700'">
              <input v-model="regCode" type="radio" name="register-reason" :value="key" class="w-4 h-4 shrink-0"/>
              <span>{{ label }}</span>
            </label>
          </div>
        </fieldset>
        <div>
          <label for="register-untracked-reason" class="block text-xs font-bold text-gray-600 dark:text-gray-300 mb-1">التفاصيل</label>
          <textarea id="register-untracked-reason" v-model="regReason" rows="3" maxlength="300" dir="auto"
                    class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white resize-none [unicode-bidi:plaintext]"></textarea>
          <p class="mt-1 text-[11px] text-amber-700 dark:text-amber-400">لا تكتب بيانات شخصية (رقم، جواز، عنوان).</p>
        </div>
        <p v-if="regError" role="alert" class="text-xs text-red-600 dark:text-red-400">❌ {{ regError }}</p>
      </form>
      <template #footer>
        <button type="button" class="min-h-11 px-4 py-2 rounded-xl text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200" @click="registering = null">إلغاء</button>
        <button type="submit" form="register-untracked" :disabled="busy || !regCode || regReason.trim().length < 10"
                class="min-h-11 px-5 py-2 rounded-xl text-sm font-bold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50">تسجيل</button>
      </template>
    </AppModal>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import AppModal from '@/Components/AppModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { errorText } from '@/composables/whatsapp/useInbox.js';
import { when } from '@/utils/waSettings.js';

const props = defineProps({
  title: String,
  active: { type: Array, default: () => [] },
  history: { type: Array, default: () => [] },
  lastSync: { type: Object, default: null },
  reasons: { type: Object, default: () => ({}) },
  channelReady: Boolean,
});

const TABS = [{ key: 'active', label: 'القائم' }, { key: 'history', label: 'السجل' }];
const STATE = {
  blocked: { label: '✓ محظور عند ميتا', cls: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' },
  not_found: { label: '⚠️ غير موجود عند ميتا', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' },
  mismatch: { label: '⚠️ وصلت منه رسالة', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' },
  unblocked: { label: 'مفكوك', cls: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' },
};
const VIA = { dashboard: 'من الصندوق', admin_number: 'من المساعد الإداري', meta_sync: 'مسجّلاً من المزامنة' };
const KIND = { phone: 'بلا محادثة', bsuid: 'بلا محادثة', erased: '🗑️ حُذفت بياناته بطلبه' };
const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };

const { confirm, prompt } = useConfirm();
const tab = ref('active');
const rows = computed(() => (tab.value === 'active' ? props.active : props.history));
const summary = computed(() => result.value || props.lastSync);
const syncing = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const result = ref(null);
const registering = ref(null);
const regCode = ref('');
const regReason = ref('');
const regError = ref('');

const refresh = () => router.reload({ only: ['active', 'history', 'lastSync'], preserveScroll: true });

/** نداء بخطأ عربي واحد؛ يعيد البيانات أو null */
async function call(url, payload, done) {
  busy.value = true;
  error.value = '';
  notice.value = '';
  try {
    const { data } = await axios.post(url, payload, api);
    notice.value = done;
    return data;
  } catch (e) {
    error.value = errorText(e, 'تعذّر تنفيذ الطلب');
    return null;
  } finally {
    busy.value = false;
  }
}

async function sync() {
  if (syncing.value) return;
  syncing.value = true;
  error.value = '';
  notice.value = '';
  try {
    const { data } = await axios.post('/api/whatsapp/blocks/sync', {}, api);
    result.value = { not_found: [], untracked: [], mismatch: [], ...data };
    refresh();
  } catch (e) {
    error.value = errorText(e, 'تعذّرت قراءة قائمة ميتا');
  } finally {
    syncing.value = false;
  }
}

const askReason = (title, message) => prompt({ title, label: 'السبب', message, required: true, maxLength: 300, confirmLabel: title });

async function unblock(b) {
  const localOnly = b.meta_state === 'not_found';
  const reason = await askReason(localOnly ? 'إنهاء السجل عندنا' : 'فك الحظر',
    localOnly ? 'الحظر غير موجود عند ميتا أصلاً: يُنهى السجل عندنا بلا نداء لميتا.' : 'يعود العميل قادراً على مراسلتنا ونعود قادرين على مراسلته. البوت لا يعود تلقائياً.');
  if (!reason) return;
  if (await call(`/api/whatsapp/blocks/${b.id}/unblock`, { reason }, localOnly ? 'أُنهي السجل.' : 'فُكّ الحظر.')) refresh();
}

async function reblock(b) {
  if (!(await confirm({ title: 'إعادة الحظر عند ميتا', message: 'يُرسل الحظر لميتا من جديد بالسبب المسجّل نفسه.', confirmLabel: '⛔ إعادة الحظر', danger: true }))) return;
  if (await call(`/api/whatsapp/blocks/${b.id}/reblock`, {}, 'أُعيد الحظر عند ميتا.')) refresh();
}

const dropUntracked = (ref_) => {
  if (result.value) result.value = { ...result.value, untracked: result.value.untracked.filter((u) => u.ref !== ref_) };
};

function openRegister(u) {
  registering.value = u;
  regCode.value = '';
  regReason.value = '';
  regError.value = '';
}

async function register() {
  const u = registering.value;
  if (!u || busy.value) return;
  const data = await call('/api/whatsapp/blocks/untracked/register', { ref: u.ref, reason_code: regCode.value, reason: regReason.value.trim() }, 'سُجّل السبب.');
  if (!data) {
    regError.value = error.value;
    return;
  }
  registering.value = null;
  dropUntracked(u.ref);
  refresh();
}

async function unblockUntracked(u) {
  const reason = await askReason('فك الحظر', 'يُفك الحظر عند ميتا. لا سجل عندنا لهذا الحظر، فيبقى الفك في سجل التدقيق.');
  if (!reason) return;
  if (await call('/api/whatsapp/blocks/untracked/unblock', { ref: u.ref, reason }, 'فُكّ الحظر.')) dropUntracked(u.ref);
}
</script>
