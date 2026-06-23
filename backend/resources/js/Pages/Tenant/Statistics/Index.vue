<script setup lang="ts">
// Task 2 will implement this component.
defineProps<{
    kpis: { arrived: number; noShow: number; notRecorded: number; rate: number | null }
    perPractitioner: Array<{ id: number; name: string; color: string; arrived: number; noShow: number; rate: number | null }>
    filters: { from: string; to: string }
    scoped: boolean
}>()
</script>

<template>
    <div />
</template>
