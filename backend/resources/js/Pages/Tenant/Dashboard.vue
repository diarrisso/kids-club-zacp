<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
defineOptions({ layout: TenantLayout })
</script>

<template>
  <Head title="Dashboard" />
  <div class="p-8">
    <PageHeader title="Cabinet Dashboard" />
  </div>
</template>
