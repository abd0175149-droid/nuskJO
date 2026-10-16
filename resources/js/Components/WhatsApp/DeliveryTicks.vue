<template>
  <!-- علامات التسليم (E21): ✓ أُرسلت، ✓✓ سُلّمت، ✓✓ زرقاء قُرئت، و⚠ فشل على أحد أجهزة العميل. ⏳ و⚠️ و❌ من E19 في الفقاعة نفسها -->
  <span v-if="tick" class="inline-flex items-center gap-1" data-test="delivery-ticks">
    <span role="img" :aria-label="tick.label" :title="tick.title" :data-status="status"
          class="font-bold leading-none" :class="tick.cls" dir="ltr">{{ tick.glyph }}</span>
    <span v-if="multiFail" role="img" aria-label="فشل التسليم على أحد أجهزة العميل" title="فشل التسليم على أحد أجهزة العميل"
          class="leading-none" data-test="multi-fail">⚠︎</span>
  </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  status: { type: String, default: '' },
  // HH:MM بتوقيت وقت الرسالة، أو null لصف قديم بلا وقت من ميتا
  statusAt: { type: String, default: null },
  multiFail: { type: Boolean, default: false },
});

const NOT_READ = 'غياب علامة القراءة لا يعني أن العميل لم يقرأ؛ قد يكون أطفأ إيصالات القراءة';

// القراءة على شارة زرقاء بعلامتين بيضاوين: تُقرأ فوق الأخضر والذهبي والرمادي في الوضعين (التباين في tests/js)
const READ = 'px-1 rounded bg-sky-700 text-white';

const tick = computed(() => {
  const at = props.statusAt ? ` ${props.statusAt}` : '';
  switch (props.status) {
    case 'sent':
      return { glyph: '✓', label: 'أُرسلت', title: 'أُرسلت', cls: 'opacity-70' };
    case 'delivered':
      return { glyph: '✓✓', label: `سُلّمت${at}`, title: `سُلّمت${at} — ${NOT_READ}`, cls: '' };
    case 'read':
      return { glyph: '✓✓', label: `قُرئت${at}`, title: `قُرئت${at}`, cls: READ };
    case 'played':
      return { glyph: '✓✓', label: `شُغّلت${at}`, title: `شُغّلت${at}`, cls: READ };
    default:
      return null;
  }
});
</script>
