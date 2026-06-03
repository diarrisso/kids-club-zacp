<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ButtonLink from '@/components/ui/ButtonLink.vue'
import DataTable from '@/components/ui/DataTable.vue'
import ColorDot from '@/components/ui/ColorDot.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import RowActions from '@/components/ui/RowActions.vue'
defineOptions({ layout: TenantLayout })

defineProps<{ practitioners: Array<{
  id: number; first_name: string; last_name: string; title: string;
  email: string; color: string; is_active: boolean;
}> }>()

const destroy = (id: number) => {
  if (confirm('Wirklich löschen?')) router.delete(`/behandler/${id}`)
}
</script>

<template>
  <Head title="Behandler" />
  <div class="p-8">
    <PageHeader title="Behandler">
      <template #action>
        <ButtonLink href="/behandler/create">+ Neuer Behandler</ButtonLink>
      </template>
    </PageHeader>
    <DataTable>
      <template #head>
        <tr>
          <th class="p-3 text-left">Name</th>
          <th class="p-3 text-left">E-Mail</th>
          <th class="p-3">Farbe</th>
          <th class="p-3">Status</th>
          <th class="p-3"></th>
        </tr>
      </template>
      <tr v-for="p in practitioners" :key="p.id" class="border-t">
        <td class="p-3">{{ p.title }} {{ p.first_name }} {{ p.last_name }}</td>
        <td class="p-3">{{ p.email }}</td>
        <td class="p-3 text-center"><ColorDot :color="p.color" /></td>
        <td class="p-3 text-center"><StatusBadge :active="p.is_active" /></td>
        <td class="p-3"><RowActions :edit-href="`/behandler/${p.id}/edit`" @delete="destroy(p.id)" /></td>
      </tr>
    </DataTable>
  </div>
</template>
