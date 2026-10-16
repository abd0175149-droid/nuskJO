<template>
  <!-- فرض التحقق بخطوتين قادم (E27، MFA_ENFORCE=warn): صاحب الصلاحية الحساسة بلا عامل ثانٍ يرى هذا في كل صفحة -->
  <div v-if="needed" role="status"
       class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
    <span class="font-bold">🔐 فعّل التحقق بخطوتين<template v-if="from"> قبل <span dir="ltr">{{ from }}</span></template> — صلاحياتك تتطلبه</span>
    <Link href="/profile/security" class="ms-auto min-h-11 inline-flex items-center font-bold underline underline-offset-4">التفعيل الآن ←</Link>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const needed = computed(() => !!page.props.mfa?.needed && !String(page.url || '').startsWith('/profile/security'));
const from = computed(() => page.props.mfa?.enforce_from || null);
</script>
