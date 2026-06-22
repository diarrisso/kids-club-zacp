<script setup lang="ts">
import TenantLayout from '@/Layouts/TenantLayout.vue';

defineProps<{
    appointments: {
        data: Record<string, unknown>[];
        links: Record<string, unknown>[];
        meta: Record<string, unknown>;
    };
    filters: {
        q: string | null;
        from: string | null;
        to: string | null;
        attendance: string | null;
    };
}>();
</script>

<template>
    <TenantLayout title="Terminliste">
        <!-- Placeholder: full implementation in Task 6 -->
    </TenantLayout>
</template>
