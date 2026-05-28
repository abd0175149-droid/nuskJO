<template>
    <AppLayout v-if="isAdmin">
        <template #header><slot name="header" /></template>
        <slot />
    </AppLayout>
    <EmployeeLayout v-else>
        <template #header><slot name="header" /></template>
        <slot />
    </EmployeeLayout>
</template>

<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from './AppLayout.vue';
import EmployeeLayout from './EmployeeLayout.vue';

const page = usePage();
const isAdmin = computed(() => page.props.auth?.isAdmin === true);
</script>
