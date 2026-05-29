<script setup lang="ts">
import { reactive, computed } from 'vue'

defineProps<{ serverErrors: Record<string, string[]> }>()
const emit = defineEmits<{ submit: [payload: Record<string, unknown>] }>()

const form = reactive({
    patient_first_name: '', patient_last_name: '', patient_birthdate: '',
    parent_first_name: '', parent_last_name: '', parent_email: '', parent_phone: '',
    notes_parent: '', consent: false, website: '', // website = honeypot
})

const valid = computed(() =>
    !!form.patient_first_name && !!form.patient_last_name && !!form.patient_birthdate &&
    !!form.parent_first_name && !!form.parent_last_name && /\S+@\S+\.\S+/.test(form.parent_email) &&
    form.consent === true,
)

const submit = () => { if (valid.value) emit('submit', { ...form }) }
</script>

<template>
    <form @submit.prevent="submit">
        <h2 class="text-lg font-bold mb-4">Ihre Angaben</h2>

        <fieldset class="mb-4">
            <legend class="font-medium mb-2">Kind</legend>
            <input name="patient_first_name" v-model="form.patient_first_name" placeholder="Vorname"
                   class="w-full p-2 border rounded mb-2">
            <input name="patient_last_name" v-model="form.patient_last_name" placeholder="Nachname"
                   class="w-full p-2 border rounded mb-2">
            <input name="patient_birthdate" v-model="form.patient_birthdate" type="date"
                   class="w-full p-2 border rounded">
        </fieldset>

        <fieldset class="mb-4">
            <legend class="font-medium mb-2">Eltern</legend>
            <input name="parent_first_name" v-model="form.parent_first_name" placeholder="Vorname"
                   class="w-full p-2 border rounded mb-2">
            <input name="parent_last_name" v-model="form.parent_last_name" placeholder="Nachname"
                   class="w-full p-2 border rounded mb-2">
            <input name="parent_email" v-model="form.parent_email" type="email" placeholder="E-Mail"
                   class="w-full p-2 border rounded mb-1">
            <div v-if="serverErrors.parent_email" class="text-red-600 text-sm mb-2">{{ serverErrors.parent_email[0] }}</div>
            <input name="parent_phone" v-model="form.parent_phone" placeholder="Telefon (optional)"
                   class="w-full p-2 border rounded">
        </fieldset>

        <textarea name="notes_parent" v-model="form.notes_parent" placeholder="Notiz (optional)"
                  class="w-full p-2 border rounded mb-3" rows="2"></textarea>

        <!-- Honeypot: hidden from humans, bots fill it -->
        <input name="website" v-model="form.website" tabindex="-1" autocomplete="off"
               style="position:absolute;left:-9999px" aria-hidden="true">

        <label class="flex items-start gap-2 mb-4">
            <input name="consent" v-model="form.consent" type="checkbox" class="mt-1">
            <span class="text-sm">Ich willige in die Verarbeitung der angegebenen Daten zur Terminbuchung ein.</span>
        </label>

        <button type="submit" :disabled="!valid"
                class="w-full bg-blue-700 text-white py-3 rounded disabled:opacity-50">
            Termin buchen
        </button>
    </form>
</template>
