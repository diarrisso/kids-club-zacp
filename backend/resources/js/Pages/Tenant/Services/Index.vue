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

defineProps<{ services: Array<{
  id: number; name: string; duration_minutes: number;
  color: string; is_active: boolean;
}> }>()

const destroy = (id: number) => {
  if (confirm('Wirklich löschen?')) router.delete(`/leistungen/${id}`)
}
</script>

<template>
  <Head title="Leistungen" />
  <div class="p-8">
    <PageHeader title="Leistungen">
      <template #action>
        <ButtonLink href="/leistungen/create">+ Neue Leistung</ButtonLink>
      </template>
    </PageHeader>
    <DataTable>
      <template #head>
        <tr>
          <th class="p-3 text-left">Bezeichnung</th>
          <th class="p-3">Dauer</th>
          <th class="p-3">Farbe</th>
          <th class="p-3">Status</th>
          <th class="p-3"></th>
        </tr>
      </template>
      <tr v-for="s in services" :key="s.id" class="border-t">
        <td class="p-3">{{ s.name }}</td>
        <td class="p-3 text-center">{{ s.duration_minutes }} min</td>
        <td class="p-3 text-center"><ColorDot :color="s.color" /></td>
        <td class="p-3 text-center"><StatusBadge :active="s.is_active" /></td>
        <td class="p-3"><RowActions :edit-href="`/leistungen/${s.id}/edit`" @delete="destroy(s.id)" /></td>
      </tr>
    </DataTable>
  </div>
</template>
