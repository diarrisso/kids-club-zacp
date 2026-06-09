<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import TextInput from '@/components/ui/TextInput.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
  exception: null | {
    id: number; practitioner_id: number; starts_at: string;
    ends_at: string; type: string; reason: string | null;
  };
  practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>;
}>()

const types = [
  { value: 'vacation', label: 'Urlaub' },
  { value: 'sick', label: 'Krankheit' },
  { value: 'block', label: 'Blockierung' },
]

const form = useForm({
  practitioner_id: props.exception?.practitioner_id ?? props.practitioners[0]?.id ?? null,
  starts_at: props.exception?.starts_at ?? '',
  ends_at: props.exception?.ends_at ?? '',
  type: props.exception?.type ?? 'vacation',
  reason: props.exception?.reason ?? '',
  is_cabinet_closure: false,
})

const submit = () => {
  if (props.exception) form.put(`/abwesenheiten/${props.exception.id}`)
  else form.post('/abwesenheiten')
}
</script>

<template>
  <Head :title="exception ? 'Abwesenheit bearbeiten' : 'Neue Abwesenheit'" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">
      {{ exception ? 'Abwesenheit bearbeiten' : 'Neue Abwesenheit' }}
    </h1>
    <Card as="form" @submit.prevent="submit">
      <div v-if="!exception" class="mb-4 flex items-center gap-3 p-3 rounded-xl bg-amber-50 border border-amber-200">
        <button type="button"
          @click="form.is_cabinet_closure = !form.is_cabinet_closure"
          :class="['relative w-10 h-6 rounded-full transition', form.is_cabinet_closure ? 'bg-slate-800' : 'bg-slate-300']">
          <span :class="['absolute top-0.5 w-5 h-5 bg-white rounded-full transition-transform',
            form.is_cabinet_closure ? 'translate-x-4' : 'translate-x-0.5']" />
        </button>
        <span class="text-sm font-semibold text-amber-900">Betriebsschließung (alle Behandler)</span>
        <span v-if="form.is_cabinet_closure" class="text-xs text-amber-700">— erstellt eine Ausnahme pro aktivem Behandler</span>
      </div>
      <FormField v-if="!form.is_cabinet_closure" label="Behandler" required>
        <select v-model.number="form.practitioner_id" class="w-full p-2 border rounded">
          <option v-for="p in practitioners" :key="p.id" :value="p.id">
            {{ p.title }} {{ p.first_name }} {{ p.last_name }}
          </option>
        </select>
      </FormField>
      <FormField v-if="!form.is_cabinet_closure" label="Typ" required>
        <select v-model="form.type" class="w-full p-2 border rounded">
          <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </FormField>
      <div class="grid grid-cols-2 gap-4">
        <FormField label="Von" required>
          <input v-model="form.starts_at" type="datetime-local" required class="w-full p-2 border rounded">
        </FormField>
        <FormField label="Bis" required :error="form.errors.ends_at">
          <input v-model="form.ends_at" type="datetime-local" required class="w-full p-2 border rounded">
        </FormField>
      </div>
      <FormField label="Grund">
        <TextInput v-model="form.reason" />
      </FormField>
      <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
    </Card>
  </div>
</template>
