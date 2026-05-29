<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
defineOptions({ layout: TenantLayout })
</script>

<template>
    <Head title="Dashboard" />
    <div class="p-8"><h1 class="text-3xl font-bold">Cabinet Dashboard</h1></div>
</template>
