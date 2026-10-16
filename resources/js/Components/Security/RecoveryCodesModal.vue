<template>
  <!-- رموز الاسترداد تُعرض مرة واحدة (E27): لا تُغلق النافذة قبل «حفظتُها»، والنسخ والتنزيل محليان في المتصفح -->
  <AppModal :open="!!codes" title="🔑 رموز الاسترداد" size="sm" close-label="إغلاق بعد الحفظ" @close="tryClose">
    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
      احفظها الآن في مكان آمن خارج هاتفك (مدير كلمات المرور أو ورقة). كل رمز يُستعمل مرة واحدة للدخول إن فقدت هاتفك،
      ولن تُعرض ثانية.
    </p>
    <ol class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm" dir="ltr">
      <li v-for="code in codes || []" :key="code" class="rounded-lg bg-gray-100 dark:bg-gray-800 px-2 py-1.5 text-center select-all">{{ code }}</li>
    </ol>
    <div class="mt-3 flex flex-wrap gap-2">
      <button type="button" @click="copy" class="min-h-11 px-3 rounded-xl text-sm font-bold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">
        {{ copied ? '✅ نُسخت' : '📋 نسخ' }}
      </button>
      <button type="button" @click="download" class="min-h-11 px-3 rounded-xl text-sm font-bold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">⬇️ تنزيل نصي</button>
    </div>
    <label class="mt-4 flex items-center gap-2 text-sm font-bold cursor-pointer">
      <input v-model="saved" type="checkbox" class="w-5 h-5 rounded text-gold-500 focus:ring-gold-500" />
      حفظتُها في مكان آمن
    </label>
    <p v-if="nudged && !saved" role="alert" class="mt-1 text-xs text-red-600 dark:text-red-400">أكّد أنك حفظت الرموز أولاً.</p>
    <template #footer>
      <button type="button" @click="tryClose" :disabled="!saved"
              class="min-h-11 px-4 rounded-xl text-sm font-bold bg-gold-500 hover:bg-gold-400 text-black disabled:opacity-50">تم</button>
    </template>
  </AppModal>
</template>

<script setup>
import { ref, watch } from 'vue';
import AppModal from '../AppModal.vue';

const props = defineProps({ codes: { type: Array, default: null } });
const emit = defineEmits(['close']);

const saved = ref(false);
const copied = ref(false);
const nudged = ref(false);

watch(() => props.codes, () => {
  saved.value = false;
  copied.value = false;
  nudged.value = false;
});

const text = () => ['رموز استرداد نُسك — كل رمز مرة واحدة', new Date().toISOString().slice(0, 10), '', ...(props.codes || [])].join('\n');

async function copy() {
  try {
    await navigator.clipboard.writeText((props.codes || []).join('\n'));
    copied.value = true;
  } catch {
    copied.value = false;
  }
}

function download() {
  const url = URL.createObjectURL(new Blob([text()], { type: 'text/plain;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = 'nusk-recovery-codes.txt';
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function tryClose() {
  if (!saved.value) {
    nudged.value = true;
    return;
  }
  emit('close');
}
</script>
