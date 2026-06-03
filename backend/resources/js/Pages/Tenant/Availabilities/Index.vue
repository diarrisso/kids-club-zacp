<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ButtonLink from '@/components/ui/ButtonLink.vue'
import DataTable from '@/components/ui/DataTable.vue'
import ColorDot from '@/components/ui/ColorDot.vue'
import RowActions from '@/components/ui/RowActions.vue'
defineOptions({ layout: TenantLayout })

const days: Record<number, string> = {
  1: 'Mo', 2: 'Di', 3: 'Mi', 4: 'Do', 5: 'Fr', 6: 'Sa', 7: 'So',
}

defineProps<{
  availabilities: Array<{
    id: number; day_of_week: number; start_time: string; end_time: string;
    practitioner: { first_name: string; last_name: string; color: string };
  }>;
}>()

const destroy = (id: number) => {
  if (confirm('Wirklich löschen?')) router.delete(`/sprechzeiten/${id}`)
}
</script>

<template>
  <Head title="Sprechzeiten" />
  <div class="p-8">
    <PageHeader title="Sprechzeiten">
      <template #action>
        <ButtonLink href="/sprechzeiten/create">+ Neue Sprechzeit</ButtonLink>
      </template>
    </PageHeader>
    <DataTable>
      <template #head>
        <tr>
          <th class="p-3 text-left">Behandler</th>
          <th class="p-3 text-left">Wochentag</th>
          <th class="p-3 text-left">Zeit</th>
          <th class="p-3"></th>
        </tr>
      </template>
      <tr v-for="a in availabilities" :key="a.id" class="border-t">
        <td class="p-3">
          <ColorDot :color="a.practitioner.color" size="sm" class="mr-2" />
          {{ a.practitioner.first_name }} {{ a.practitioner.last_name }}
        </td>
        <td class="p-3">{{ days[a.day_of_week] }}</td>
        <td class="p-3">{{ a.start_time }} – {{ a.end_time }}</td>
        <td class="p-3"><RowActions :edit-href="`/sprechzeiten/${a.id}/edit`" @delete="destroy(a.id)" /></td>
      </tr>
    </DataTable>
  </div>
</template>
