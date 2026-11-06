<template>
  <AppLayout>
    <template #header>حدود الإرسال</template>

    <div class="space-y-6 pb-4">
      <div class="p-4 rounded-xl border text-sm leading-relaxed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 text-gray-600 dark:text-gray-300 space-y-1.5">
        <p>حدود الإرسال الاستباقي وحده: رسائل النظام والمتابعات. <b>الردود</b> على العملاء (البوت والموظف) لا تخضع لها أبداً.</p>
        <p>كل حقل فارغ يرث من الصف الأعم: «كل الأرقام × كل الأنواع» ← النوع ← الرقم ← الرقم والنوع معاً. الرقم غير رسمي، فالحدود المحافظة تحميه من الحظر.</p>
      </div>

      <section class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm border-gray-200 dark:border-gray-700 overflow-x-auto">
        <div class="p-4 flex items-center justify-between gap-3 flex-wrap">
          <h3 class="font-bold">الحدود</h3>
          <div class="flex gap-2 text-sm">
            <select v-model="scope.channel_id" aria-label="الرقم" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
              <option :value="null">كل الأرقام</option>
              <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
        </div>
        <table class="w-full text-sm">
          <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-xs text-gray-500">
            <th class="p-2 text-start">النوع</th>
            <th v-for="f in FIELDS" :key="f.key" class="p-2">{{ f.label }}</th>
            <th class="p-2"></th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-for="t in typeRows" :key="t.key ?? 'all'">
              <td class="p-2 font-bold whitespace-nowrap">{{ t.label }}</td>
              <td v-for="f in FIELDS" :key="f.key" class="p-1">
                <input v-if="f.kind === 'num'" type="number" min="0" :value="cell(t.key, f.key)" :placeholder="inherited(t.key, f.key)"
                       @change="setCell(t.key, f.key, $event.target.value)" :aria-label="t.label + ' ' + f.label"
                       class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm text-center" />
                <input v-else-if="f.kind === 'bool'" type="checkbox" :checked="!!(cell(t.key, f.key) ?? inheritedRaw(t.key, f.key))" @change="setCell(t.key, f.key, $event.target.checked)" :aria-label="t.label + ' ' + f.label" />
              </td>
              <td class="p-1 whitespace-nowrap">
                <button type="button" @click="saveRow(t.key)" :disabled="!pending[rowKey(t.key)]" class="px-2 py-1 rounded-lg text-xs font-bold text-black bg-gold-400 disabled:opacity-30">حفظ</button>
                <button v-if="(t.key || scope.channel_id) && existing(t.key)" type="button" @click="removeRow(t.key)" class="px-2 py-1 rounded-lg text-xs text-red-600">↺ وراثة</button>
              </td>
            </tr>
          </tbody>
        </table>
        <p class="p-3 text-xs text-gray-500">النافذة بساعات عمّان، والفاصل بالثواني بين رسالتين على الرقم نفسه. «توزيع» يوزّع رسائل اليوم على النافذة كلها بدل دفعة.</p>
      </section>

      <section class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm border-gray-200 dark:border-gray-700 p-4 space-y-3">
        <h3 class="font-bold">سلّم التسخين</h3>
        <p class="text-xs text-gray-500">سقف الرقم اليومي حسب عمره منذ أول ربط. رقم قديم معروف: غيّر تاريخ بدء تسخينه للماضي.</p>
        <div class="flex flex-wrap gap-2 items-center">
          <div v-for="(s, i) in ladder" :key="i" class="flex items-center gap-1 text-sm rounded-lg bg-gray-50 dark:bg-gray-800 p-2">
            من اليوم <input v-model.number="s[0]" type="number" min="0" class="w-14 rounded border-gray-300 dark:bg-gray-900 text-center" :aria-label="'اليوم ' + (i + 1)" />
            : <input v-model.number="s[1]" type="number" min="0" class="w-20 rounded border-gray-300 dark:bg-gray-900 text-center" :aria-label="'السقف ' + (i + 1)" /> رسالة
            <button type="button" class="text-red-600 text-xs" @click="ladder.splice(i, 1)" aria-label="حذف الدرجة">✕</button>
          </div>
          <button type="button" class="text-xs font-bold text-gold-700" @click="ladder.push([ (ladder.at(-1)?.[0] || 0) + 7, (ladder.at(-1)?.[1] || 40) * 2 ])">+ درجة</button>
          <button type="button" @click="saveLadder" class="px-3 py-1.5 rounded-lg text-xs font-bold text-black bg-gold-400">حفظ السلّم</button>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <div v-for="c in channels" :key="c.id" class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-sm space-y-1">
            <p class="font-bold flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" :style="{ background: c.color }"></span>{{ c.name }}</p>
            <p>اليوم: <b>{{ c.used_today }}</b> من <b>{{ Math.min(c.warmup_cap, c.effective.per_day ?? Infinity) }}</b></p>
            <label class="flex items-center gap-2 text-xs">بدء التسخين
              <input type="date" :value="c.warmup_started_at" @change="saveWarmup(c, $event.target.value)" class="rounded border-gray-300 dark:bg-gray-800 text-xs" dir="ltr" />
            </label>
          </div>
        </div>
      </section>
      <p v-if="notice" role="status" class="p-3 rounded-xl text-sm" :class="notice.ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">{{ notice.text }}</p>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  rows: { type: Array, default: () => [] },
  defaults: { type: Object, default: () => ({}) },
  channels: { type: Array, default: () => [] },
  types: { type: Array, default: () => [] },
  groups: { type: Object, default: () => ({}) },
  ladder: { type: Array, default: () => [] },
});

const FIELDS = [
  { key: 'per_minute', label: 'في الدقيقة', kind: 'num' }, { key: 'per_hour', label: 'في الساعة', kind: 'num' },
  { key: 'per_day', label: 'في اليوم', kind: 'num' }, { key: 'cold_per_day', label: 'محادثات جديدة/يوم', kind: 'num' },
  { key: 'per_recipient_day', label: 'للعميل/يوم', kind: 'num' }, { key: 'min_gap_sec', label: 'الفاصل (ث)', kind: 'num' },
  { key: 'window_from', label: 'من الساعة', kind: 'num' }, { key: 'window_to', label: 'إلى الساعة', kind: 'num' },
  { key: 'spread', label: 'توزيع', kind: 'bool' },
];
const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const scope = reactive({ channel_id: null });
const data = ref(props.rows.map((r) => ({ ...r })));
const pending = reactive({});
const ladder = ref(props.ladder.map((s) => [...s]));
const notice = ref(null);

const typeRows = computed(() => [{ key: null, label: 'كل الأنواع' }, ...props.types.map((t) => ({ key: t.key, label: t.label }))]);
const rowKey = (type) => `${scope.channel_id ?? '*'}|${type ?? '*'}`;
const find = (ch, type) => data.value.find((r) => (r.channel_id ?? null) === ch && (r.type_key ?? null) === type);
const existing = (type) => !!find(scope.channel_id, type);
function cell(type, f) {
  const p = pending[rowKey(type)];
  if (p && f in p) return p[f];
  return find(scope.channel_id, type)?.[f] ?? null;
}
function inheritedRaw(type, f) {
  for (const [c, t] of [[scope.channel_id, null], [null, type], [null, null]]) {
    if (c === scope.channel_id && t === type) continue;
    const v = find(c, t)?.[f];
    if (v !== null && v !== undefined) return v;
  }
  // سقف فارغ في الصف الأساسي = بلا سقف؛ النافذة والفاصل من الكود
  const caps = ['per_minute', 'per_hour', 'per_day', 'cold_per_day', 'per_recipient_day'];
  return find(null, null) && caps.includes(f) ? null : props.defaults[f];
}
const inherited = (type, f) => String(inheritedRaw(type, f) ?? '∞');
function setCell(type, f, v) {
  const k = rowKey(type);
  pending[k] = { ...(pending[k] || {}), [f]: typeof v === 'boolean' ? v : v === '' ? null : Number(v) };
}

async function saveRow(type) {
  const k = rowKey(type);
  const merged = { ...(find(scope.channel_id, type) || {}), ...pending[k] };
  try {
    await axios.put('/api/whatsapp/limits', { channel_id: scope.channel_id, type_key: type, ...Object.fromEntries(FIELDS.map((f) => [f.key, merged[f.key] ?? null])) }, api);
    const row = find(scope.channel_id, type);
    if (row) Object.assign(row, pending[k]); else data.value.push({ channel_id: scope.channel_id, type_key: type, ...pending[k] });
    delete pending[k];
    notice.value = { ok: true, text: 'حُفظت الحدود' };
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر الحفظ') };
  }
}

async function removeRow(type) {
  try {
    await axios.put('/api/whatsapp/limits', { channel_id: scope.channel_id, type_key: type, remove: true }, api);
    data.value = data.value.filter((r) => r !== find(scope.channel_id, type));
    notice.value = { ok: true, text: 'عاد الصف يرث من الأعم' };
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر الحذف') };
  }
}

async function saveLadder() {
  try {
    const { data: d } = await axios.put('/api/whatsapp/limits/ladder', { ladder: ladder.value }, api);
    ladder.value = d.ladder.map((s) => [...s]);
    router.reload({ only: ['channels'] });
    notice.value = { ok: true, text: 'حُفظ سلّم التسخين' };
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر الحفظ') };
  }
}

async function saveWarmup(c, date) {
  try {
    await axios.put(`/api/whatsapp/limits/warmup/${c.id}`, { date }, api);
    router.reload({ only: ['channels'] });
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر الحفظ') };
  }
}
</script>
