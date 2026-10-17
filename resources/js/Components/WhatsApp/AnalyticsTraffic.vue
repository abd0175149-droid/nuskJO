<template>
  <!-- تبويب «الحركة» (E24): الوارد والصادر، وساعات الذروة، والبوت مقابل الموظف، وأول رد بشري، وتحوّل الطلب إلى فاتورة.
       رسوم CSS بلا مكتبة، وكل رقم من تجميع SQL في الخادم -->
  <div class="space-y-5">
    <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5" data-test="traffic-bars">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400">الوارد والصادر — {{ periodLabel }}</p>
        <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded" :style="{ background: C_IN }"></span> وارد {{ num(totals.in) }}</span>
          <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded" :style="{ background: C_OUT }"></span> صادر {{ num(totals.out) }}</span>
        </div>
      </div>
      <div class="overflow-x-auto">
        <div class="flex items-end gap-1 h-40 min-w-full" :style="{ width: series.length > 24 ? `${series.length * 18}px` : '100%' }" role="img"
             :aria-label="`الوارد والصادر لكل ${period === '24h' ? 'ساعة' : 'يوم'}`">
          <div v-for="(b, i) in series" :key="b.key" class="flex-1 flex flex-col items-center justify-end h-full min-w-0" :title="`${b.label}: وارد ${b.in} · صادر ${b.out}`">
            <div class="flex items-end gap-px w-full h-full justify-center">
              <div class="w-1/2 max-w-3 rounded-t" :style="{ height: pct(b.in), background: C_IN }"></div>
              <div class="w-1/2 max-w-3 rounded-t" :style="{ height: pct(b.out), background: C_OUT }"></div>
            </div>
            <span class="text-[9px] text-gray-400 tnum mt-1 h-3" dir="ltr">{{ i % labelEvery === 0 ? b.label : '' }}</span>
          </div>
        </div>
      </div>
      <p v-if="!max" class="text-xs text-gray-400 mt-2">لا حركة في هذه الفترة.</p>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5" data-test="peak">
        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-3">🔥 ساعات الذروة (الوارد، بتوقيت عمّان)</p>
        <div class="grid gap-px text-[9px]" style="grid-template-columns: 3.25rem repeat(24, minmax(0, 1fr));">
          <span></span>
          <span v-for="h in 24" :key="`h${h}`" class="text-center text-gray-400 tnum" dir="ltr">{{ (h - 1) % 6 === 0 ? h - 1 : '' }}</span>
          <template v-for="d in DAYS_ORDER" :key="`d${d}`">
            <span class="text-gray-500 dark:text-gray-400 truncate">{{ DAYS[d] }}</span>
            <span v-for="(n, h) in grid[d] || []" :key="`c${d}-${h}`" class="aspect-square rounded-sm"
                  :class="n ? '' : 'bg-gray-100 dark:bg-gray-800'" :style="n ? { background: heat(n) } : {}"
                  :title="`${DAYS[d]} ${h}:00 — ${n}`"></span>
          </template>
        </div>
        <p class="text-[11px] text-gray-400 mt-2">الأغمق أكثر رسائل؛ أعلى خانة {{ num(peak.max) }}.</p>
      </section>

      <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 space-y-3" data-test="bot-staff">
        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400">🤖 البوت مقابل 👤 الموظف</p>
        <div class="flex h-3 rounded-full overflow-hidden bg-gray-100 dark:bg-gray-800">
          <div :style="{ width: share(totals.bot + totals.rule), background: C_IN }"></div>
          <div :style="{ width: share(totals.staff), background: C_GOLD }"></div>
          <div :style="{ width: share(totals.system), background: C_OUT }"></div>
        </div>
        <dl class="grid grid-cols-3 gap-2 text-xs">
          <div><dt class="text-gray-500 dark:text-gray-400">البوت والقواعد</dt><dd class="font-bold tnum" dir="ltr">{{ num(totals.bot + totals.rule) }}</dd></div>
          <div><dt class="text-gray-500 dark:text-gray-400">الموظفون</dt><dd class="font-bold tnum" dir="ltr">{{ num(totals.staff) }}</dd></div>
          <div><dt class="text-gray-500 dark:text-gray-400">النظام</dt><dd class="font-bold tnum" dir="ltr">{{ num(totals.system) }}</dd></div>
        </dl>

        <div class="pt-3 border-t border-gray-100 dark:border-gray-700/50" data-test="first-human">
          <p class="text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">
            ⏱️ أول رد بشري بعد التحويل
            <span v-if="first.estimated" class="ms-1 px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300 font-normal">تقديري</span>
          </p>
          <p class="text-sm text-gray-800 dark:text-gray-100">
            الوسيط <b class="tnum">{{ dur(first.median_s) }}</b> · p90 <b class="tnum">{{ dur(first.p90_s) }}</b>
          </p>
          <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ num(first.handoffs) }} تحويلاً، رُدّ على {{ num(first.answered) }}، وبلا رد بشري بعد {{ num(first.unanswered) }}.</p>
        </div>
      </section>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 space-y-3" data-test="quotes">
        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400">💰 تحوّل طلب التسعير إلى فاتورة</p>
        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
          <p class="text-2xl font-bold tnum" :style="{ color: C_WON }" dir="ltr">{{ quotes.rate ?? 0 }}%</p>
          <p class="text-xs text-gray-500 dark:text-gray-400">{{ num(quotes.invoiced) }} فاتورة من {{ num(quotes.requests) }} طلباً · تمّ الحجز {{ num(quotes.won) }}</p>
        </div>
        <p class="text-xs text-gray-600 dark:text-gray-300">قيمة الفواتير المعتمدة المرتبطة: <b class="tnum" dir="ltr">{{ jod(quotes.invoiced_value_jod) }}</b> د.أ</p>
        <ul v-if="quotes.by_type?.length" class="space-y-1 text-xs">
          <li v-for="t in quotes.by_type" :key="t.type" class="flex items-center justify-between gap-2">
            <span class="text-gray-700 dark:text-gray-200">{{ t.label }}</span>
            <span class="tnum text-gray-500 dark:text-gray-400" dir="ltr">{{ t.invoiced }}/{{ t.requests }}</span>
          </li>
        </ul>
      </section>

      <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5" data-test="top">
        <p class="text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 mb-3">💬 أكثر المحادثات نشاطاً</p>
        <ul v-if="top.length" class="space-y-1.5 text-sm">
          <li v-for="c in top" :key="c.id" class="flex items-center justify-between gap-2 min-w-0">
            <a :href="`/whatsapp/inbox?conv=${c.id}`" class="truncate text-blue-700 dark:text-blue-400 hover:underline min-w-0">
              <bdi dir="auto" class="[unicode-bidi:plaintext]">{{ c.name }}</bdi>
            </a>
            <span class="tnum text-xs text-gray-500 dark:text-gray-400 shrink-0" dir="ltr">{{ c.messages }} ({{ c.inbound }} ↓)</span>
          </li>
        </ul>
        <p v-else class="py-6 text-center text-sm text-gray-400">لا محادثات في هذه الفترة.</p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  traffic: { type: Object, required: true },
  period: { type: String, default: '7d' },
});

const C_IN = '#3987e5';
const C_OUT = '#d95926';
const C_GOLD = '#c5a059';
const C_WON = '#199e70';
const PERIODS = { '24h': 'آخر 24 ساعة', '7d': 'آخر 7 أيام', '30d': 'آخر 30 يوماً' };
const DAYS = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
// أسبوع العمل يبدأ السبت
const DAYS_ORDER = [6, 0, 1, 2, 3, 4, 5];

const n = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);
const num = (v) => n(v).toLocaleString('en-US');
const jod = (v) => n(v).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

const periodLabel = computed(() => PERIODS[props.period] || props.period);
const series = computed(() => props.traffic?.series?.series || []);
const totals = computed(() => ({ in: 0, out: 0, bot: 0, staff: 0, system: 0, rule: 0, ...(props.traffic?.series?.totals || {}) }));
const max = computed(() => Math.max(0, ...series.value.map((b) => Math.max(n(b.in), n(b.out)))));
const pct = (v) => (max.value ? `${Math.max(n(v) > 0 ? 2 : 0, Math.round((n(v) / max.value) * 100))}%` : '0%');
const labelEvery = computed(() => (series.value.length > 12 ? Math.ceil(series.value.length / 8) : 1));

const peak = computed(() => props.traffic?.peak || { grid: [], max: 0 });
const grid = computed(() => peak.value.grid || []);
const heat = (v) => `rgba(57, 135, 229, ${(0.15 + 0.85 * (n(v) / Math.max(1, n(peak.value.max)))).toFixed(2)})`;

const outTotal = computed(() => totals.value.bot + totals.value.rule + totals.value.staff + totals.value.system);
const share = (v) => (outTotal.value ? `${((n(v) / outTotal.value) * 100).toFixed(1)}%` : '0%');

const first = computed(() => props.traffic?.first_human || {});
const quotes = computed(() => props.traffic?.quotes || {});
const top = computed(() => props.traffic?.top || []);

/** مدة مقروءة: ثوانٍ ثم دقائق ثم ساعات */
function dur(s) {
  const v = n(s);
  if (!v) return '—';
  if (v < 60) return `${v} ث`;
  if (v < 3600) return `${Math.round(v / 60)} د`;
  return `${(v / 3600).toFixed(1)} س`;
}

defineExpose({ dur });
</script>

<style scoped>
.tnum {
  font-variant-numeric: tabular-nums;
}
</style>
