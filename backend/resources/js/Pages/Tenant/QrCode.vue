<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

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

const copied = ref(false)

function submit() {
    form.post('/termin-qr-code', {
        preserveScroll: true,
        onSuccess: () => { version.value++ },
    })
}

async function copyImageUrl() {
    try {
        await navigator.clipboard.writeText(absolutePngUrl.value)
        copied.value = true
        setTimeout(() => { copied.value = false }, 1500)
    } catch {
        // Clipboard API unavailable (non-secure context) — fail quietly; the field is selectable manually.
    }
}
</script>

<template>
    <Head title="QR-Code" />
    <div class="max-w-2xl mx-auto p-8 space-y-6">
        <h1 class="text-3xl font-bold">QR-Code – Terminbuchung</h1>

        <form @submit.prevent="submit" class="space-y-3">
            <label class="block text-sm font-medium" for="booking_url">
                URL der Buchungsseite (WordPress)
            </label>
            <input
                id="booking_url"
                v-model="form.booking_url"
                type="url"
                required
                placeholder="https://praxis.de/termin"
                class="w-full rounded border px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <p v-if="form.errors.booking_url" class="text-sm text-red-600">
                {{ form.errors.booking_url }}
            </p>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-blue-700 px-4 py-2 text-white hover:bg-blue-800 disabled:opacity-50"
            >
                Speichern
            </button>
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

            <div class="space-y-1">
                <label class="block text-sm font-medium" for="qr_email_url">Bild-URL für E-Mails</label>
                <div class="flex gap-2">
                    <input
                        id="qr_email_url"
                        :value="absolutePngUrl"
                        readonly
                        class="w-full rounded border px-3 py-2 bg-gray-50"
                    />
                    <button
                        type="button"
                        @click="copyImageUrl"
                        class="rounded border px-3 py-2 whitespace-nowrap hover:bg-slate-50"
                    >
                        {{ copied ? 'Kopiert ✓' : 'Kopieren' }}
                    </button>
                </div>
            </div>
        </div>

        <p v-else class="text-sm text-slate-500">
            Geben Sie zuerst die URL der Buchungsseite ein, um den QR-Code zu erzeugen.
        </p>
    </div>
</template>
