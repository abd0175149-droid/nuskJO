<template>
  <!-- بطاقة رمز QR ورابط قصير (E38): الصورة والرسالة المعبأة والرابط والتنزيل والعدّادات. القياس داخلي تقريبي -->
  <article class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 space-y-2 text-sm" :data-test="`qr-${link.id}`">
    <div class="flex items-start gap-3">
      <img v-if="link.png_url && link.has_png" :src="link.png_url" :alt="`رمز QR ${link.label || link.channel_label}`"
           class="w-24 h-24 shrink-0 rounded-lg border border-gray-200 dark:border-gray-700 bg-white"/>
      <div v-else class="w-24 h-24 shrink-0 grid place-items-center rounded-lg border border-dashed border-gray-300 dark:border-gray-600 text-[11px] text-gray-400 text-center p-1">
        {{ link.status === 'failed' ? 'لم يُنشأ' : 'بلا صورة' }}
      </div>
      <div class="min-w-0 flex-1 space-y-1">
        <p class="flex flex-wrap items-center gap-1.5">
          <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200">{{ link.channel_label }}</span>
          <bdi v-if="link.label" dir="auto" class="font-bold text-gray-800 dark:text-gray-100 break-words">{{ link.label }}</bdi>
          <span v-if="link.status === 'failed'" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">فشل</span>
          <span v-if="link.status === 'active' && !link.token_ok" data-test="token-missing"
                class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-600 text-white">المرجع حُذف من الرسالة</span>
          <span v-if="link.on_card" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gold-100 text-gold-800 dark:bg-gold-900/30 dark:text-gold-300">🖨️ على البطاقة</span>
        </p>
        <p class="text-xs text-gray-700 dark:text-gray-200 break-words">
          <bdi dir="auto" class="[unicode-bidi:plaintext]">{{ link.prefilled_message }}</bdi>
          <span class="ms-1 tabular-nums" :class="link.prefilled_len > 140 ? 'text-red-600 dark:text-red-400 font-bold' : 'text-gray-400'"><bdi dir="ltr">{{ link.prefilled_len }}/140</bdi></span>
        </p>
        <p v-if="link.deep_link_url" class="flex flex-wrap items-center gap-1.5 text-xs">
          <bdi dir="ltr" class="font-mono break-all text-gray-600 dark:text-gray-300">{{ link.deep_link_url }}</bdi>
          <button type="button" @click="copy" class="min-h-11 lg:min-h-0 px-2 py-0.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300">{{ copied ? 'نُسخ ✓' : 'نسخ' }}</button>
        </p>
        <p v-if="link.last_error" class="text-[11px] text-red-600 dark:text-red-400">❌ {{ link.last_error }}</p>
        <p v-if="link.status === 'active'" class="text-[11px] text-gray-500 dark:text-gray-400" data-test="qr-usage">
          بدأت منه {{ link.conversations }} محادثة، و{{ link.quotes }} طلب تسعير (30 يوماً)
        </p>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-1.5">
      <template v-if="link.status === 'active'">
        <a :href="link.png_url" download class="min-h-11 lg:min-h-0 inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">⬇️ PNG</a>
        <a :href="link.svg_url" download class="min-h-11 lg:min-h-0 inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">⬇️ SVG</a>
        <label v-if="cardToggle && canManage" class="min-h-11 lg:min-h-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs border border-gray-200 dark:border-gray-700 cursor-pointer">
          <input type="checkbox" class="w-4 h-4" :checked="link.on_card" :disabled="busy" @change="$emit('toggleCard', link)"/>
          اطبعه على البطاقة
        </label>
      </template>
      <button v-if="canManage" type="button" :disabled="busy" @click="$emit('remove', link)"
              class="ms-auto min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 disabled:opacity-50">🗑️ حذف</button>
    </div>
  </article>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
  link: { type: Object, required: true },
  canManage: Boolean,
  // مفتاح «اطبعه على البطاقة» لرموز العروض وحدها
  cardToggle: Boolean,
  busy: Boolean,
});
defineEmits(['remove', 'toggleCard']);

const copied = ref(false);
async function copy() {
  try {
    await navigator.clipboard.writeText(props.link.deep_link_url);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 1500);
  } catch {
    copied.value = false;
  }
}
</script>
