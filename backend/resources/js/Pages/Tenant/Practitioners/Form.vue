<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import TextInput from '@/components/ui/TextInput.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
  practitioner: null | {
    id: number; first_name: string; last_name: string; title: string;
    email: string; color: string; is_active: boolean;
  }
}>()

const form = useForm({
  first_name: props.practitioner?.first_name ?? '',
  last_name: props.practitioner?.last_name ?? '',
  title: props.practitioner?.title ?? '',
  email: props.practitioner?.email ?? '',
  color: props.practitioner?.color ?? '#0a6cb3',
  is_active: props.practitioner?.is_active ?? true,
})

const submit = () => {
  if (props.practitioner) form.put(`/behandler/${props.practitioner.id}`)
  else form.post('/behandler')
}
</script>

<template>
  <Head :title="practitioner ? 'Behandler bearbeiten' : 'Neuer Behandler'" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">
      {{ practitioner ? 'Behandler bearbeiten' : 'Neuer Behandler' }}
    </h1>
    <Card as="form" @submit.prevent="submit">
      <FormField label="Anrede">
        <TextInput v-model="form.title" placeholder="Dr., Zahnärztin, ..." />
      </FormField>
      <div class="grid grid-cols-2 gap-4">
        <FormField label="Vorname" required :error="form.errors.first_name">
          <TextInput v-model="form.first_name" required />
        </FormField>
        <FormField label="Nachname" required :error="form.errors.last_name">
          <TextInput v-model="form.last_name" required />
        </FormField>
      </div>
      <FormField label="E-Mail" :error="form.errors.email">
        <input v-model="form.email" type="email" class="w-full p-2 border rounded">
      </FormField>
      <FormField label="Farbe im Kalender">
        <input v-model="form.color" type="color" class="h-10 w-20 border rounded">
      </FormField>
      <label class="flex items-center gap-2">
        <input v-model="form.is_active" type="checkbox"> Aktiv
      </label>
      <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
    </Card>
  </div>
</template>
