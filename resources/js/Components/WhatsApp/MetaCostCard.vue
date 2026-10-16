<template>
  <!-- بطاقة «تكلفة ميتا التقديرية» (E24): رسائل الخدمة المحتسبة هذا الشهر مقابل المجانية، وصادر اليوم بمصادره، والرسائل
       الآلية بميزانياتها، والتقدير بسعر يدخله المالك. تقديري دائماً: الفاتورة في Business Manager هي المرجع -->
  <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5 space-y-4" data-test="meta-cost">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <p class="text-sm font-bold text-gray-800 dark:text-gray-100">💵 تكلفة ميتا التقديرية</p>
      <span class="px-2 py-0.5 rounded text-[11px] bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300">تقديري</span>
    </div>

    <div>
      <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
        <span class="text-gray-700 dark:text-gray-200">رسائل خدمة محتسبة هذا الشهر</span>
        <span class="font-bold tnum" dir="ltr" data-test="billable">{{ num(cost.service_billable_month) }} / {{ num(cost.service_free_tier) }}</span>
      </div>
      <div class="mt-1.5 h-2.5 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
        <div class="h-full rounded-full" :class="usedPct >= 90 ? 'bg-red-500' : usedPct >= 70 ? 'bg-amber-500' : 'bg-green-500'" :style="{ width: `${usedPct}%` }"></div>
      </div>
      <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">
        المتبقي من المجانية {{ num(cost.service_free_remaining) }}.
        <template v-if="cost.service_rate_usd !== null && cost.service_rate_usd !== undefined">
          التقدير بعد المجانية: <b class="tnum" dir="ltr">${{ money(cost.service_cost_estimate_usd) }}</b> (السعر <bdi dir="ltr">${{ cost.service_rate_usd }}</bdi> للرسالة).
        </template>
        <template v-else>أدخل سعر رسالة الخدمة في الإعدادات («حدود الصادر») ليظهر التقدير.</template>
      </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
      <div>
        <p class="font-bold text-gray-600 dark:text-gray-300 mb-1">📤 صادر اليوم: {{ num(cost.today?.total) }} <span class="font-normal text-gray-400">(حد التنبيه {{ num(cost.daily_alert_threshold) }})</span></p>
        <p class="text-gray-600 dark:text-gray-300">
          <template v-for="(v, source, i) in cost.today?.by_source || {}" :key="source"><template v-if="i"> · </template>{{ SOURCES[source] || source }} {{ num(v) }}</template>
          <template v-if="cost.today?.test"> · تجريبي {{ num(cost.today.test) }}</template>
        </p>
        <p class="text-gray-500 dark:text-gray-400 mt-1">الإرسال المبادر اليوم {{ num(cost.system_initiated_today) }} من سقف {{ num(cost.system_daily_cap) }}.</p>
      </div>
      <div>
        <p class="font-bold text-gray-600 dark:text-gray-300 mb-1">🤖 الرسائل الآلية اليوم بميزانياتها</p>
        <ul class="flex flex-wrap gap-1.5" data-test="budgets">
          <li v-for="a in cost.automated || []" :key="a.kind" class="px-2 py-0.5 rounded-lg border"
              :class="a.over ? 'border-red-300 text-red-700 dark:border-red-700 dark:text-red-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300'">
            {{ KINDS[a.kind] || a.kind }} {{ num(a.count) }}<template v-if="a.budget !== null">/{{ num(a.budget) }}</template>
          </li>
        </ul>
      </div>
    </div>

    <div v-if="cost.pricing?.length" class="text-xs">
      <p class="font-bold text-gray-600 dark:text-gray-300 mb-1">فئات التسعير هذا الشهر (كما أبلغت ميتا)</p>
      <ul class="space-y-0.5">
        <li v-for="p in cost.pricing" :key="`${p.category}-${p.type}-${p.billable}`" class="flex items-center justify-between gap-2 text-gray-600 dark:text-gray-300">
          <bdi dir="ltr" class="font-mono">{{ p.category }} · {{ p.type || '—' }}</bdi>
          <span class="tnum" dir="ltr">{{ num(p.count) }} {{ p.billable ? '💲' : '' }}</span>
        </li>
      </ul>
    </div>

    <p class="text-xs text-gray-600 dark:text-gray-300">كلفة نموذج الذكاء في الفترة: <b class="tnum" dir="ltr">${{ money(cost.model_cost_usd) }}</b></p>
    <p v-if="cost.meta" class="text-xs text-gray-600 dark:text-gray-300" data-test="meta-reconcile">
      <template v-if="cost.meta.ok">ميتا تقول عن <bdi dir="ltr">{{ cost.meta.day }}</bdi>: أُرسل {{ num(cost.meta.meta_sent) }} وسُلّم {{ num(cost.meta.meta_delivered) }} · عدّنا {{ num(cost.meta.ours) }} (للمقارنة فقط).</template>
      <template v-else>تعذّرت قراءة تحليلات ميتا ({{ cost.meta.reason }}).</template>
    </p>
    <p class="text-[11px] text-gray-400">{{ cost.note }}</p>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ cost: { type: Object, required: true } });

const SOURCES = { bot: 'البوت', staff: 'موظف', system: 'النظام', rule: 'قاعدة حتمية', proactive: 'مبادر', template: 'قالب' };
const KINDS = { reply: 'ردود البوت', rule: 'القواعد الحتمية', admin: 'المساعد الإداري', outage: 'تعطل المزوّد', reqcontact: 'طلب الرقم', consent: 'موافقة الملفات', optout: 'تأكيد الإيقاف', other: 'أخرى' };
const n = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);
const num = (v) => n(v).toLocaleString('en-US');
const money = (v) => n(v).toFixed(4);
const usedPct = computed(() => Math.min(100, Math.round((n(props.cost.service_billable_month) / Math.max(1, n(props.cost.service_free_tier))) * 100)));
</script>

<style scoped>
.tnum {
  font-variant-numeric: tabular-nums;
}
</style>
