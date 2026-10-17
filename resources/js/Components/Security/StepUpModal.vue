<template>
  <!-- تأكيد الهوية قبل فعل حساس (E27): مثيل واحد في AppLayout وEmployeeLayout، على AppModal فيُحبس التركيز -->
  <AppModal :open="active && stepUpState.open" title="🔐 تأكيد هويتك" size="sm" close-label="إلغاء" @close="cancel">
    <form v-if="!confirmedWithoutReplay" class="space-y-3" @submit.prevent="submit">
      <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
        {{ method === 'totp' ? 'إجراء حساس: اكتب الرمز الظاهر الآن في تطبيق المصادقة.' : 'إجراء حساس: أكّد كلمة مرورك للمتابعة.' }}
        <span class="block text-xs text-gray-400 dark:text-gray-500">لن يُطلب منك ثانية قبل ربع ساعة.</span>
      </p>
      <div>
        <label :for="inputId" class="block text-xs font-bold text-gray-600 dark:text-gray-300 mb-1">{{ method === 'totp' ? 'رمز التحقق (6 أرقام)' : 'كلمة المرور' }}</label>
        <input v-if="method === 'totp'" :id="inputId" v-model="value" data-autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="10" dir="ltr"
               :aria-invalid="error ? 'true' : undefined"
               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-center text-lg tracking-[0.4em] font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none" />
        <input v-else :id="inputId" v-model="value" data-autofocus type="password" autocomplete="current-password" dir="ltr"
               :aria-invalid="error ? 'true' : undefined"
               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none" />
        <p v-if="error" role="alert" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ error }}</p>
      </div>
      <button type="submit" class="hidden" tabindex="-1" aria-hidden="true"></button>
    </form>
    <p v-else role="status" class="text-sm font-bold text-green-700 dark:text-green-400">✅ تم التحقق — أعد تنفيذ العملية.</p>
    <template #footer>
      <template v-if="!confirmedWithoutReplay">
        <button type="button" @click="cancel" class="min-h-11 px-4 rounded-xl text-sm font-bold border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">إلغاء</button>
        <button type="button" @click="submit" :disabled="busy || !value.trim()"
                class="min-h-11 px-4 rounded-xl text-sm font-bold bg-gold-500 hover:bg-gold-400 text-black disabled:opacity-50">{{ busy ? 'جارٍ التحقق…' : 'تأكيد' }}</button>
      </template>
      <button v-else type="button" @click="cancel" class="min-h-11 px-4 rounded-xl text-sm font-bold bg-gold-500 hover:bg-gold-400 text-black">حسناً</button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppModal from '../AppModal.vue';
import { registerStepUpHost, settleStepUp, stepUpState } from '@/composables/useStepUp.js';

const page = usePage();
const method = computed(() => page.props.mfa?.stepup_method || 'password');
const inputId = `stepup-${useId()}`;
const value = ref('');
const error = ref('');
const busy = ref(false);
// فُتحت بعلامة بلا زيارة ملتقطة: نجح التأكيد، والمستخدم يعيد الفعل بنفسه
const confirmedWithoutReplay = ref(false);

const host = registerStepUpHost();
onBeforeUnmount(() => host.release());
const active = computed(() => stepUpState.hosts[0] === host.id);

watch(() => stepUpState.open, (open) => {
  if (open) {
    value.value = '';
    error.value = '';
    confirmedWithoutReplay.value = false;
  }
}, { immediate: true });

async function submit() {
  if (busy.value || !value.value.trim()) return;
  busy.value = true;
  error.value = '';
  try {
    const field = method.value === 'totp' ? 'code' : 'password';
    await axios.post('/security/confirm', { [field]: value.value.trim() }, { headers: { Accept: 'application/json' } });
    if (stepUpState.viaFlash && !stepUpState.replayable) {
      confirmedWithoutReplay.value = true;
      return;
    }
    settleStepUp(true);
  } catch (e) {
    const status = e?.response?.status;
    if (status === 401) {
      window.location.assign('/login');
      return;
    }
    error.value = status === 429
      ? 'محاولات كثيرة، انتظر دقيقة ثم أعد المحاولة.'
      : (Object.values(e?.response?.data?.errors || {})[0]?.[0] || e?.response?.data?.message || 'تعذّر التحقق. أعد المحاولة.');
  } finally {
    busy.value = false;
  }
}

function cancel() {
  if (busy.value) return;
  settleStepUp(confirmedWithoutReplay.value);
  confirmedWithoutReplay.value = false;
}
</script>
