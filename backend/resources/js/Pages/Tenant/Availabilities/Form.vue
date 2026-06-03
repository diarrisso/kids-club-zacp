<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
  availability: null | {
    id: number; practitioner_id: number; day_of_week: number;
    start_time: string; end_time: string;
  };
  practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>;
}>()

const days = [
  { value: 1, label: 'Montag' }, { value: 2, label: 'Dienstag' },
  { value: 3, label: 'Mittwoch' }, { value: 4, label: 'Donnerstag' },
  { value: 5, label: 'Freitag' }, { value: 6, label: 'Samstag' },
  { value: 7, label: 'Sonntag' },
]

const form = useForm({
  practitioner_id: props.availability?.practitioner_id ?? props.practitioners[0]?.id ?? null,
  day_of_week: props.availability?.day_of_week ?? 1,
  start_time: props.availability?.start_time ?? '09:00',
  end_time: props.availability?.end_time ?? '17:00',
})

const submit = () => {
  if (props.availability) form.put(`/sprechzeiten/${props.availability.id}`)
  else form.post('/sprechzeiten')
}
</script>

<template>
  <Head :title="availability ? 'Sprechzeit bearbeiten' : 'Neue Sprechzeit'" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">
      {{ availability ? 'Sprechzeit bearbeiten' : 'Neue Sprechzeit' }}
    </h1>
    <Card as="form" @submit.prevent="submit">
      <FormField label="Behandler" required :error="form.errors.practitioner_id">
        <select v-model.number="form.practitioner_id" class="w-full p-2 border rounded">
          <option v-for="p in practitioners" :key="p.id" :value="p.id">
            {{ p.title }} {{ p.first_name }} {{ p.last_name }}
          </option>
        </select>
      </FormField>
      <FormField label="Wochentag" required>
        <select v-model.number="form.day_of_week" class="w-full p-2 border rounded">
          <option v-for="d in days" :key="d.value" :value="d.value">{{ d.label }}</option>
        </select>
      </FormField>
      <div class="grid grid-cols-2 gap-4">
        <FormField label="Von" required>
          <input v-model="form.start_time" type="time" required class="w-full p-2 border rounded">
        </FormField>
        <FormField label="Bis" required :error="form.errors.end_time">
          <input v-model="form.end_time" type="time" required class="w-full p-2 border rounded">
        </FormField>
      </div>
      <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
    </Card>
  </div>
</template>
