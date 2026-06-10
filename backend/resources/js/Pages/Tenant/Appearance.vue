<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, onUnmounted, ref } from 'vue'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import FormField from '@/components/ui/FormField.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'

defineOptions({ layout: TenantLayout })

interface ThemeProps {
    colorPrimary: string; colorPrimaryTo: string; colorAccent: string
    colorBackground: string; colorText: string
    fontHeading: string; fontBody: string; radius: string
}

const props = defineProps<{
    theme: ThemeProps
    logoUrl: string | null
    datenschutzUrl: string | null
    impressumUrl: string | null
    fontOptions: string[]
}>()

const DEFAULTS = {
    colorPrimary: '#6B8FA3', colorPrimaryTo: '#C40C78', colorAccent: '#EC0A8C',
    colorBackground: '#FFFFFF', colorText: '#26257F',
    fontHeading: 'Fredoka', fontBody: 'Nunito', radius: 26,
}

const form = useForm({
    colorPrimary: props.theme.colorPrimary,
    colorPrimaryTo: props.theme.colorPrimaryTo,
    colorAccent: props.theme.colorAccent,
    colorBackground: props.theme.colorBackground,
    colorText: props.theme.colorText,
    fontHeading: props.theme.fontHeading,
    fontBody: props.theme.fontBody,
    radius: Number.isNaN(parseInt(props.theme.radius, 10)) ? 26 : parseInt(props.theme.radius, 10),
    logo: null as File | null,
    remove_logo: false,
    datenschutz_url: props.datenschutzUrl ?? '',
    impressum_url: props.impressumUrl ?? '',
})

const logoPreview = ref<string | null>(props.logoUrl)

// Revoke a previously created blob: URL (never the server-provided logoUrl).
function revokePreviewBlob() {
    if (logoPreview.value && logoPreview.value !== props.logoUrl) {
        URL.revokeObjectURL(logoPreview.value)
    }
}

onUnmounted(revokePreviewBlob)

function onLogoChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null
    form.logo = file
    form.remove_logo = false
    if (file) {
        revokePreviewBlob()
        logoPreview.value = URL.createObjectURL(file)
    }
}

function removeLogo() {
    form.logo = null
    form.remove_logo = true
    revokePreviewBlob()
    logoPreview.value = null
}

function resetDefaults() {
    Object.assign(form, DEFAULTS)
}

const fontStack = (name: string) =>
    name === 'System' ? 'system-ui, sans-serif' : `'${name}', system-ui, sans-serif`

// Live preview vars — bound to the UNSAVED form state, mirroring the widget's
// --masinga-* token contract (tints derived from accent exactly like widget.css).
const previewVars = computed(() => ({
    '--masinga-primary': form.colorPrimary,
    '--masinga-primary-to': form.colorPrimaryTo,
    '--masinga-accent': form.colorAccent,
    '--masinga-bg': form.colorBackground,
    '--masinga-text': form.colorText,
    '--masinga-radius': `${form.radius}px`,
    '--masinga-tint': `color-mix(in srgb, ${form.colorAccent} 13%, white)`,
    '--masinga-tint-soft': `color-mix(in srgb, ${form.colorAccent} 7%, white)`,
    '--masinga-gradient': `linear-gradient(135deg, ${form.colorPrimary} 0%, ${form.colorPrimaryTo} 100%)`,
    fontFamily: fontStack(form.fontBody),
}))

const colorFields = [
    { key: 'colorPrimary', label: 'Primärfarbe (Verläufe, Buttons)' },
    { key: 'colorPrimaryTo', label: 'Verlaufsfarbe (zweiter Verlaufston)' },
    { key: 'colorAccent', label: 'Akzentfarbe (Auswahl, Fokus, Badges)' },
    { key: 'colorBackground', label: 'Hintergrund der Karte' },
    { key: 'colorText', label: 'Textfarbe' },
] as const

function submit() {
    form.post('/erscheinungsbild', {
        preserveScroll: true,
        forceFormData: true,
        // Don't re-upload the same file (or re-send remove_logo) on a subsequent
        // save; the blob preview keeps showing it, props refresh on next visit.
        onSuccess: () => { form.reset('logo', 'remove_logo') },
    })
}
</script>

<template>
    <Head title="Erscheinungsbild" />
    <div class="max-w-6xl mx-auto p-8">
        <h1 class="text-3xl font-bold">Erscheinungsbild des Buchungs-Widgets</h1>
        <p class="mt-1 text-slate-500 text-sm">Farben, Logo, Schrift und Form — Änderungen wirken nach dem Speichern sofort auf der Praxis-Website.</p>

        <div v-if="!form.datenschutz_url" data-dsgvo-warning
             class="mt-4 rounded-xl bg-amber-50 ring-1 ring-amber-200 px-4 py-3 text-sm text-amber-800">
            <strong>DSGVO-Hinweis:</strong> Es ist keine Datenschutzerklärung verlinkt. Die Einwilligung im
            Widget ist ohne diesen Link nicht vollständig informiert. Bitte URL unten eintragen.
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-8">
            <form class="space-y-5" @submit.prevent="submit">
                <section class="space-y-3">
                    <h2 class="font-semibold text-slate-700">Farben</h2>
                    <FormField v-for="f in colorFields" :key="f.key" :label="f.label" :label-for="f.key" :error="(form.errors as any)[f.key]">
                        <div class="flex items-center gap-3">
                            <input :id="f.key" type="color" v-model="(form as any)[f.key]" class="h-10 w-14 rounded border p-1" />
                            <input type="text" v-model="(form as any)[f.key]" pattern="#[0-9A-Fa-f]{6}" :aria-label="`${f.label} (Hex)`"
                                   class="w-28 p-2 border rounded font-mono text-sm" />
                        </div>
                    </FormField>
                </section>

                <section class="space-y-3">
                    <h2 class="font-semibold text-slate-700">Schrift &amp; Form</h2>
                    <FormField label="Schrift Überschriften" label-for="fontHeading" :error="form.errors.fontHeading">
                        <select id="fontHeading" v-model="form.fontHeading" class="w-full p-2 border rounded">
                            <option v-for="f in fontOptions" :key="f" :value="f">{{ f }}</option>
                        </select>
                    </FormField>
                    <FormField label="Schrift Fließtext" label-for="fontBody" :error="form.errors.fontBody">
                        <select id="fontBody" v-model="form.fontBody" class="w-full p-2 border rounded">
                            <option v-for="f in fontOptions" :key="f" :value="f">{{ f }}</option>
                        </select>
                    </FormField>
                    <FormField :label="`Eckenradius — ${form.radius}px`" label-for="radius" :error="form.errors.radius">
                        <input id="radius" type="range" min="0" max="40" v-model.number="form.radius" class="w-full" />
                    </FormField>
                </section>

                <section class="space-y-3">
                    <h2 class="font-semibold text-slate-700">Logo</h2>
                    <FormField label="Logo (PNG, JPG oder WebP — max. 512 KB)" label-for="logo" :error="form.errors.logo">
                        <input id="logo" type="file" accept=".png,.jpg,.jpeg,.webp" @change="onLogoChange"
                               class="w-full text-sm" />
                    </FormField>
                    <div v-if="logoPreview" class="flex items-center gap-3">
                        <img :src="logoPreview" alt="Logo-Vorschau" class="h-12 w-auto rounded border bg-white p-1" />
                        <button type="button" class="text-sm text-rose-600 underline" @click="removeLogo">Logo entfernen</button>
                    </div>
                </section>

                <section class="space-y-3">
                    <h2 class="font-semibold text-slate-700">Rechtliches</h2>
                    <FormField label="URL der Datenschutzerklärung" label-for="datenschutz_url" :error="form.errors.datenschutz_url">
                        <input id="datenschutz_url" v-model="form.datenschutz_url" type="url"
                               placeholder="https://praxis.de/datenschutz" class="w-full p-2 border rounded" />
                    </FormField>
                    <FormField label="URL des Impressums (optional)" label-for="impressum_url" :error="form.errors.impressum_url">
                        <input id="impressum_url" v-model="form.impressum_url" type="url"
                               placeholder="https://praxis.de/impressum" class="w-full p-2 border rounded" />
                    </FormField>
                </section>

                <div class="flex items-center gap-3 pt-2">
                    <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
                    <button type="button" class="rounded border px-3 py-2 text-sm hover:bg-slate-50" @click="resetDefaults">
                        Auf Standard zurücksetzen
                    </button>
                    <span v-if="form.recentlySuccessful" data-saved role="status" class="text-sm text-emerald-600">Gespeichert.</span>
                </div>
            </form>

            <div>
                <h2 class="font-semibold text-slate-700 mb-3">Live-Vorschau</h2>
                <p class="text-xs text-slate-400 mb-3">Vorschau der Design-Token — das echte Widget übernimmt diese Werte nach dem Speichern.</p>
                <div data-preview :style="previewVars"
                     class="mx-auto max-w-md p-6 shadow-[0_24px_70px_-28px_rgba(30,41,59,0.30)] ring-1 ring-slate-100"
                     style="background-color: var(--masinga-bg); border-radius: var(--masinga-radius); color: var(--masinga-text);">
                    <img v-if="logoPreview" :src="logoPreview" alt="" class="mx-auto mb-3 max-h-12 w-auto" />
                    <div class="flex items-center justify-center gap-2 mb-4">
                        <span class="h-7 w-7 rounded-full flex items-center justify-center text-[11px] font-bold text-white" style="background: var(--masinga-gradient);">1</span>
                        <span class="h-1 w-8 rounded" style="background: var(--masinga-tint);"></span>
                        <span class="h-7 w-7 rounded-full flex items-center justify-center text-[11px] font-bold" style="background: var(--masinga-tint); color: var(--masinga-text);">2</span>
                    </div>
                    <h3 class="text-xl font-bold" :style="{ fontFamily: fontStack(form.fontHeading) }">
                        Termin wählen
                    </h3>
                    <div class="mt-3 rounded-2xl px-4 py-3 text-sm font-semibold ring-2"
                         style="background-color: var(--masinga-tint); --tw-ring-color: var(--masinga-accent);">
                        Erstuntersuchung Kind · 45 Min.
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <span class="rounded-xl border border-slate-200 py-2 text-center text-sm font-bold" style="background-color: var(--masinga-bg);">09:00</span>
                        <span class="rounded-xl py-2 text-center text-sm font-bold text-white" style="background: var(--masinga-gradient);">09:30</span>
                        <span class="rounded-xl border border-slate-200 py-2 text-center text-sm font-bold" style="background-color: var(--masinga-bg);">10:00</span>
                    </div>
                    <button type="button" class="mt-4 w-full rounded-2xl py-3 text-sm font-bold text-white" style="background: var(--masinga-gradient);">
                        Weiter
                    </button>
                    <p class="mt-3 text-xs" style="color: color-mix(in srgb, var(--masinga-text) 60%, transparent);">
                        Ich willige in die Verarbeitung … <span class="underline font-semibold" style="color: var(--masinga-accent);">Datenschutzerklärung</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
