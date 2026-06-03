<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ButtonLink from '@/components/ui/ButtonLink.vue'
import DataTable from '@/components/ui/DataTable.vue'
import ColorDot from '@/components/ui/ColorDot.vue'
import RowActions from '@/components/ui/RowActions.vue'
defineOptions({ layout: TenantLayout })

const labels: Record<string, string> = {
  vacation: 'Urlaub', sick: 'Krankheit', block: 'Blockierung',
}

defineProps<{
  exceptions: Array<{
    id: number; starts_at: string; ends_at: string; type: string; reason: string | null;
    practitioner: { first_name: string; last_name: string; color: string };
  }>;
}>()

const destroy = (id: number) => {
  if (confirm('Wirklich löschen?')) router.delete(`/abwesenheiten/${id}`)
}
</script>

<template>
  <Head title="Abwesenheiten" />
  <div class="p-8">
    <PageHeader title="Abwesenheiten">
      <template #action>
        <ButtonLink href="/abwesenheiten/create">+ Neue Abwesenheit</ButtonLink>
      </template>
    </PageHeader>
    <DataTable>
      <template #head>
        <tr>
          <th class="p-3 text-left">Behandler</th>
          <th class="p-3 text-left">Typ</th>
          <th class="p-3 text-left">Von</th>
          <th class="p-3 text-left">Bis</th>
          <th class="p-3 text-left">Grund</th>
          <th class="p-3"></th>
        </tr>
      </template>
      <tr v-for="e in exceptions" :key="e.id" class="border-t">
        <td class="p-3">
          <ColorDot :color="e.practitioner.color" size="sm" class="mr-2" />
          {{ e.practitioner.first_name }} {{ e.practitioner.last_name }}
        </td>
        <td class="p-3">{{ labels[e.type] ?? e.type }}</td>
        <td class="p-3">{{ e.starts_at }}</td>
        <td class="p-3">{{ e.ends_at }}</td>
        <td class="p-3">{{ e.reason }}</td>
        <td class="p-3"><RowActions :edit-href="`/abwesenheiten/${e.id}/edit`" @delete="destroy(e.id)" /></td>
      </tr>
    </DataTable>
  </div>
</template>
