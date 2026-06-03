<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import FormField from '@/components/ui/FormField.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
import CopyField from '@/components/ui/CopyField.vue'

defineOptions({ layout: TenantLayout })

const props = defineProps<{ bookingUrl: string | null }>()

const form = useForm({ booking_url: props.bookingUrl ?? '' })

// Cache-buster: force preview reload after successful save.
const version = ref(0)
const imgUrl = computed(() => `/termin-qrcode.svg?v=${version.value}`)
const pngUrl = computed(() => `/termin-qrcode.png?v=${version.value}`)
const absolutePngUrl = computed(() =>
  // Guard window for any future SSR pass; this app is currently client-only.
  `${typeof window !== 'undefined' ? window.location.origin : ''}/termin-qrcode.png`,
)

function submit() {
  form.post('/termin-qr-code', {
    preserveScroll: true,
    onSuccess: () => { version.value++ },
  })
}
</script>

<template>
  <Head title="QR-Code" />
  <div class="max-w-2xl mx-auto p-8 space-y-6">
    <h1 class="text-3xl font-bold">QR-Code – Terminbuchung</h1>

    <form class="space-y-3" @submit.prevent="submit">
      <FormField label="URL der Buchungsseite (WordPress)" required label-for="booking_url" :error="form.errors.booking_url">
        <input
          id="booking_url"
          v-model="form.booking_url"
          type="url"
          required
          placeholder="https://praxis.de/termin"
          class="w-full p-2 border rounded"
        />
      </FormField>
      <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
    </form>

    <div v-if="props.bookingUrl" class="space-y-4">
      <img :src="imgUrl" alt="QR-Code Terminbuchung" class="h-56 w-56 border rounded bg-white p-2" />

      <div class="flex gap-3">
        <a :href="pngUrl" download="termin-qrcode.png" class="rounded border px-3 py-2 hover:bg-slate-50">
          PNG herunterladen
        </a>
        <a :href="imgUrl" download="termin-qrcode.svg" class="rounded border px-3 py-2 hover:bg-slate-50">
          SVG herunterladen
        </a>
      </div>

      <CopyField label="Bild-URL für E-Mails" input-id="qr_email_url" :value="absolutePngUrl" />
    </div>

    <p v-else class="text-sm text-slate-500">
      Geben Sie zuerst die URL der Buchungsseite ein, um den QR-Code zu erzeugen.
    </p>
  </div>
</template>
