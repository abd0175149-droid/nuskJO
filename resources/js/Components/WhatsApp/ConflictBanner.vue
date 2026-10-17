<template>
    <div v-if="fields.length || others.length" role="alert"
         class="p-4 rounded-xl border text-sm border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 text-amber-900 dark:text-amber-200">
        <p class="font-bold">⚠️ غيّر أحد غيرك الإعدادات بعد فتحك الصفحة</p>
        <p v-if="fields.length" class="mt-1">تعديلاتك محفوظة في النموذج فوق قيمهم، لكن هذه الحقول عدّلها الطرفان — راجعها:
            <b>{{ fields.join('، ') }}</b></p>
        <p v-if="others.length" class="mt-1 text-xs">حدّثنا من القيم الجديدة أيضاً: {{ others.join('، ') }}</p>
        <p class="mt-1 text-xs">ثم احفظ مرة أخرى.</p>
        <button type="button" @click="$emit('dismiss')" class="mt-2 text-xs underline">إخفاء</button>
    </div>
</template>

<script setup>
// تعارض 409: الحقول التي عدّلها الطرفان (fields) وما تغيّر عند الآخر وحده (others)، بتسمياتها
defineProps({
    fields: { type: Array, default: () => [] },
    others: { type: Array, default: () => [] },
});
defineEmits(['dismiss']);
</script>
