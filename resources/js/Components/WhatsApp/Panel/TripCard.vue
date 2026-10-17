<template>
  <!-- رحلة واحدة من فاتورة (C128): الموعد والعودة والليالي، والعرض والوجهة، والمسافرون بغرفهم، والفنادق، أو بنود الفاتورة اليدوية -->
  <article class="rounded-xl border p-3 space-y-2.5" :class="highlight
             ? 'border-gold-300 dark:border-gold-700 bg-gold-50/60 dark:bg-gold-900/10'
             : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900'" data-test="trip-card">
    <div class="flex flex-wrap items-center gap-1.5">
      <span class="px-2 py-0.5 rounded-full text-[11px] font-bold" :class="phaseClass">{{ phaseText }}</span>
      <span v-if="trip.status !== 'approved'" class="px-2 py-0.5 rounded-full text-[10px] bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300">{{ trip.status_label }}</span>
      <a v-if="trip.invoice_url" :href="trip.invoice_url" class="ms-auto text-[11px] font-mono text-gray-500 dark:text-gray-400 hover:text-gold-700 dark:hover:text-gold-400 hover:underline"
         :title="`فتح الفاتورة ${trip.invoice_number}`"><bdi dir="ltr">{{ trip.invoice_number }}</bdi></a>
    </div>

    <div>
      <p class="text-sm font-bold text-gray-900 dark:text-white leading-snug">
        <a v-if="trip.offer?.url" :href="trip.offer.url" class="hover:underline"><bdi dir="auto">{{ title }}</bdi></a>
        <bdi v-else dir="auto">{{ title }}</bdi>
      </p>
      <p v-if="place" class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ place }}</p>
    </div>

    <!-- الذهاب والعودة -->
    <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 rounded-lg bg-white/70 dark:bg-gray-950/40 border border-gray-100 dark:border-gray-800 px-2.5 py-2">
      <div class="min-w-0">
        <p class="text-[10px] text-gray-500 dark:text-gray-400">🛫 الذهاب</p>
        <p class="text-xs font-bold text-gray-800 dark:text-gray-100 tabular-nums">{{ day(trip.trip_date) }}</p>
      </div>
      <span class="text-[10px] text-gray-400 text-center" aria-hidden="true">{{ trip.nights ? `${trip.nights} ليالٍ` : '←' }}</span>
      <div class="min-w-0 text-end">
        <p class="text-[10px] text-gray-500 dark:text-gray-400">🛬 العودة</p>
        <p class="text-xs font-bold text-gray-800 dark:text-gray-100 tabular-nums">{{ trip.return_date ? day(trip.return_date) : '—' }}</p>
      </div>
    </div>

    <!-- المسافرون -->
    <div v-if="trip.pax || trip.travellers.length">
      <p class="text-[11px] font-bold text-gray-600 dark:text-gray-300">👥 {{ paxText }}</p>
      <ul v-if="trip.travellers.length" class="mt-1 flex flex-wrap gap-1">
        <li v-for="(t, i) in trip.travellers" :key="i" class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
          <span class="tabular-nums">{{ t.pax }}</span> × {{ t.room || 'مقعد' }}<template v-if="t.choice"> · <bdi dir="auto">{{ t.choice }}</bdi></template>
        </li>
      </ul>
    </div>

    <!-- الفنادق -->
    <ul v-if="trip.hotels.length" class="space-y-1.5" aria-label="الفنادق">
      <li v-for="(h, i) in trip.hotels" :key="i" class="rounded-lg border border-gray-100 dark:border-gray-800 bg-white/70 dark:bg-gray-950/40 px-2.5 py-1.5">
        <div class="flex items-start gap-1.5">
          <span aria-hidden="true" class="text-sm leading-5">🏨</span>
          <div class="min-w-0 flex-1">
            <p class="text-xs font-bold text-gray-800 dark:text-gray-100">
              <span v-if="h.city" class="text-gray-500 dark:text-gray-400 font-normal">{{ h.city }} · </span><bdi dir="auto">{{ h.name }}</bdi>
              <span v-if="h.rating" class="text-gold-600 dark:text-gold-400 font-normal" :title="`${h.rating} نجوم`"> ★{{ h.rating }}</span>
            </p>
            <p v-if="hotelLine(h)" class="text-[10px] text-gray-500 dark:text-gray-400">{{ hotelLine(h) }}</p>
          </div>
        </div>
      </li>
    </ul>

    <!-- فاتورة يدوية: بنودها كما كتبها البائع -->
    <ul v-if="trip.services.length" class="text-[11px] text-gray-700 dark:text-gray-300 space-y-0.5 list-disc ps-4" aria-label="بنود الفاتورة">
      <li v-for="(s, i) in trip.services" :key="i"><bdi dir="auto">{{ s }}</bdi></li>
    </ul>
  </article>
</template>

<script setup>
import { computed } from 'vue';
import { day } from './panelFormat.js';

const props = defineProps({
  trip: { type: Object, required: true },
  highlight: Boolean,
});

const title = computed(() => props.trip.offer?.title || (props.trip.kind === 'custom' ? 'رحلة بفاتورة يدوية' : 'رحلة'));
const place = computed(() => [props.trip.offer?.destination, props.trip.offer?.travel_mode].filter(Boolean).join(' · '));

const phaseText = computed(() => {
  const t = props.trip;
  if (t.phase === 'travelling') return '🧳 مسافر الآن';
  if (t.phase === 'past') return '✔ رحلة سابقة';
  if (t.days_until === 1) return '✈️ غداً';
  if (t.days_until === 2) return '✈️ بعد يومين';
  if (t.days_until <= 10) return `✈️ بعد ${t.days_until} أيام`;
  return `✈️ بعد ${t.days_until} يوماً`;
});
const phaseClass = computed(() => ({
  travelling: 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
  past: 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300',
}[props.trip.phase] || (props.trip.days_until <= 7
  ? 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300'
  : 'bg-sky-100 dark:bg-sky-900/30 text-sky-800 dark:text-sky-300')));

const paxText = computed(() => {
  const n = props.trip.pax || props.trip.travellers.reduce((a, t) => a + t.pax, 0);
  if (n === 1) return 'مسافر واحد';
  if (n === 2) return 'مسافران';
  return n >= 3 && n <= 10 ? `${n} مسافرين` : `${n} مسافراً`;
});

const hotelLine = (h) => [h.nights ? `${h.nights} ليالٍ` : '', h.meals, h.distance ? `${h.distance} عن الحرم` : '', h.location].filter(Boolean).join(' · ');
</script>
