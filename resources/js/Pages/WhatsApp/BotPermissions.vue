<template>
  <AppLayout>
    <template #header>صلاحيات البوت</template>

    <div class="space-y-6 pb-4">
      <div class="p-4 rounded-xl border text-sm leading-relaxed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 text-gray-600 dark:text-gray-300 space-y-1.5">
        <p>أي أداة يستطيع البوت استخدامها حسب من يراسله. يُعرف المرسل من هاتفه الموقّع وحده: <b>زائر</b> لا نعرفه، <b>عميل محتمل</b> له طلب تسعير مفتوح، <b>عميل</b> مربوط بحساب، <b>مسافر الآن</b> عميل رحلته جارية.</p>
        <p>«تحويل لموظف» لا يُطفأ أبداً. أرقام الإدارة لها المساعد الإداري بصلاحيات رقمها (صفحة أرقام الإدارة).</p>
      </div>

      <section class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="bg-gray-50 dark:bg-gray-800/50 text-xs text-gray-500">
            <th class="p-2 text-start">الأداة</th>
            <th v-for="p in profiles" :key="p.key" class="p-2">{{ p.label }}</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-for="t in tools" :key="t.key">
              <td class="p-2"><span class="font-bold">{{ t.label }}</span> <span class="text-xs text-gray-400 font-mono" dir="ltr">{{ t.key }}</span>
                <span v-if="t.limited" class="text-[10px] px-1 rounded bg-sky-50 text-sky-700 ms-1" title="متاحة على الأرقام بوضع «محدود»">محدود</span></td>
              <td v-for="p in profiles" :key="p.key" class="p-2 text-center">
                <input type="checkbox" :checked="m[t.key]?.[p.key]" :disabled="t.locked" @change="toggle(t.key, p.key, $event.target.checked)"
                       :aria-label="t.label + ' — ' + p.label" :class="isDefault(t.key, p.key) ? '' : 'ring-2 ring-amber-400'" />
              </td>
            </tr>
          </tbody>
        </table>
        <div class="p-3 flex items-center gap-3 justify-end border-t border-gray-100 dark:border-gray-800">
          <span class="text-xs text-gray-500">المؤطّر بالبرتقالي يختلف عن الافتراضي.</span>
          <button type="button" @click="reset" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-600">الافتراضي</button>
          <button type="button" @click="save" :disabled="!dirty" class="px-4 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-40">حفظ</button>
        </div>
      </section>

      <section class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm border-gray-200 dark:border-gray-700 p-4 space-y-3">
        <h3 class="font-bold">البوت على كل رقم</h3>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <div v-for="c in channels" :key="c.id" class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-sm space-y-2">
            <p class="font-bold flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" :style="{ background: c.color }"></span>{{ c.name }}</p>
            <label v-for="(l, mode) in BOT_MODE_LABELS" :key="mode" class="flex items-center gap-1.5">
              <input type="radio" :name="'mode-' + c.id" :value="mode" :checked="c.bot_mode === mode" @change="setMode(c, mode)" /> {{ l }}
            </label>
          </div>
        </div>
      </section>
      <p v-if="notice" role="status" class="p-3 rounded-xl text-sm" :class="notice.ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">{{ notice.text }}</p>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { errorText } from '@/composables/whatsapp/useInbox.js';
import { BOT_MODE_LABELS } from '@/composables/whatsapp/useChannels.js';

const props = defineProps({
  tools: { type: Array, default: () => [] },
  profiles: { type: Array, default: () => [] },
  matrix: { type: Object, default: () => ({}) },
  defaults: { type: Object, default: () => ({}) },
  channels: { type: Array, default: () => [] },
});

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const m = ref(JSON.parse(JSON.stringify(props.matrix)));
const saved = ref(JSON.stringify(props.matrix));
const notice = ref(null);
const dirty = computed(() => JSON.stringify(m.value) !== saved.value);
const isDefault = (tool, p) => !!m.value[tool]?.[p] === !!props.defaults[tool]?.[p];

function toggle(tool, p, v) {
  m.value[tool] = { ...(m.value[tool] || {}), [p]: v };
}

function reset() {
  for (const t of props.tools) for (const p of props.profiles) m.value[t.key] = { ...(m.value[t.key] || {}), [p.key]: !!props.defaults[t.key]?.[p.key] };
}

async function save() {
  try {
    const { data } = await axios.put('/api/whatsapp/bot-policy', { matrix: m.value }, api);
    m.value = data.matrix;
    saved.value = JSON.stringify(data.matrix);
    notice.value = { ok: true, text: 'حُفظت الصلاحيات' };
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر الحفظ') };
  }
}

async function setMode(c, mode) {
  try {
    await axios.put(`/api/whatsapp/bot-policy/channels/${c.id}`, { bot_mode: mode }, api);
    c.bot_mode = mode;
    notice.value = { ok: true, text: `البوت على «${c.name}»: ${BOT_MODE_LABELS[mode]}` };
  } catch (e) {
    notice.value = { ok: false, text: errorText(e, 'تعذّر الحفظ') };
  }
}
</script>
