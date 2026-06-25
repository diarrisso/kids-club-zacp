<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import { BellRing, MailCheck, Bell, Mail, Check } from 'lucide-vue-next'

defineOptions({ layout: TenantLayout })

// ─── Types ───────────────────────────────────────────────────────────────────

interface Settings {
    id: number
    reminder_enabled: boolean
    reminder_channel: string
    reminder_lead_hours: number
    reminder_message: string
    booking_confirmation_enabled: boolean
    notify_on_booking: boolean
    notify_on_cancellation: boolean
}

const props = defineProps<{ settings: Settings }>()

// ─── Form state (reactive copy so we don't mutate props) ──────────────────────

const form = reactive<Settings>({ ...props.settings, reminder_channel: 'email' })

const errors = reactive<Record<string, string>>({})

// ─── Submit ───────────────────────────────────────────────────────────────────

const submit = () => {
    router.patch('/einstellungen', { ...form }, {
        preserveScroll: true,
        onStart: () => { Object.keys(errors).forEach(k => delete errors[k]) },
        onError: (e) => { Object.assign(errors, e) },
    })
}
</script>

<template>
    <Head title="Einstellungen" />

    <div class="p-8 max-w-[760px]">

        <!-- ── Page header ─────────────────────────────────────────────────── -->
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-slate-900">Einstellungen</h1>
            <button
                type="button"
                @click="submit"
                class="inline-flex items-center gap-2 bg-kids-blue text-white px-4 py-2 rounded-[8px] text-sm font-semibold shadow-sm hover:opacity-90 transition-opacity"
            >
                <Check class="h-4 w-4" :stroke-width="2.5" />
                Speichern
            </button>
        </div>

        <div class="grid gap-6">

            <!-- ── Section 1: Terminerinnerungen ───────────────────────────── -->
            <div class="bg-white rounded-ds-card border border-slate-200/70 shadow-card p-6">

                <!-- Card header -->
                <div class="flex items-start gap-3 mb-5">
                    <span class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[12px]"
                          style="background: color-mix(in srgb, var(--kids-blue) 15%, white); color: var(--kids-blue)">
                        <BellRing class="h-5 w-5" :stroke-width="1.75" />
                    </span>
                    <div class="flex-1">
                        <div class="text-lg font-bold text-slate-900">Terminerinnerungen</div>
                        <div class="text-sm text-slate-500 mt-0.5">Erinnern Sie Eltern automatisch an bevorstehende Termine.</div>
                    </div>
                    <!-- Toggle -->
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="form.reminder_enabled"
                        @click="form.reminder_enabled = !form.reminder_enabled"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-150"
                        :class="form.reminder_enabled ? 'bg-kids-blue' : 'bg-slate-200'"
                    >
                        <span
                            class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform duration-150"
                            :class="form.reminder_enabled ? 'translate-x-6' : 'translate-x-1'"
                        />
                    </button>
                </div>

                <!-- Card body — disabled when toggle is off -->
                <div
                    class="grid gap-5 transition-opacity duration-150"
                    :class="form.reminder_enabled ? 'opacity-100' : 'opacity-45 pointer-events-none'"
                >
                    <!-- Versand über (E-Mail uniquement — SMS non disponible) -->
                    <div>
                        <div class="text-sm font-medium text-slate-900 mb-2">Versand über</div>
                        <div class="inline-flex items-center gap-1.5 rounded-[8px] bg-slate-100 px-3.5 py-1.5 text-sm text-slate-700">
                            <Mail class="h-3.5 w-3.5" :stroke-width="1.75" />
                            E-Mail
                        </div>
                    </div>

                    <!-- Zeitpunkt -->
                    <div>
                        <div class="text-sm font-medium text-slate-900 mb-2">Zeitpunkt</div>
                        <div class="inline-flex bg-slate-100 rounded-[10px] p-1 gap-1">
                            <button
                                v-for="opt in [
                                    { value: 2,  label: '2 Std. vorher' },
                                    { value: 24, label: '24 Std. vorher' },
                                    { value: 48, label: '48 Std. vorher' },
                                ]"
                                :key="opt.value"
                                type="button"
                                @click="form.reminder_lead_hours = opt.value"
                                class="inline-flex items-center rounded-[8px] text-sm transition-all duration-100"
                                :class="form.reminder_lead_hours === opt.value
                                    ? 'bg-white shadow-card text-slate-900 font-semibold px-3.5 py-1.5'
                                    : 'text-slate-500 px-3.5 py-1.5'"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Nachrichtentext -->
                    <div>
                        <label for="reminder_message" class="block text-sm font-medium text-slate-900 mb-2">
                            Nachrichtentext
                        </label>
                        <textarea
                            id="reminder_message"
                            v-model="form.reminder_message"
                            rows="3"
                            maxlength="500"
                            class="w-full rounded-[8px] border border-slate-200 px-3 py-2.5 text-sm text-slate-700 resize-vertical focus:outline-none focus:ring-2 focus:ring-kids-blue/30 focus:border-kids-blue/50 transition-colors"
                            :class="errors.reminder_message ? 'border-red-400 focus:ring-red-300/30 focus:border-red-400' : ''"
                        />
                        <div class="flex items-start justify-between mt-1">
                            <p v-if="errors.reminder_message" class="text-sm text-red-600">{{ errors.reminder_message }}</p>
                            <p class="text-xs text-slate-400 ml-auto">{{ form.reminder_message.length }}/500</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section 2: Buchungsbestätigung ──────────────────────────── -->
            <div class="bg-white rounded-ds-card border border-slate-200/70 shadow-card p-6">

                <!-- Card header -->
                <div class="flex items-start gap-3 mb-5">
                    <span class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[12px]"
                          style="background: color-mix(in srgb, var(--kids-blue) 15%, white); color: var(--kids-blue)">
                        <MailCheck class="h-5 w-5" :stroke-width="1.75" />
                    </span>
                    <div class="flex-1">
                        <div class="text-lg font-bold text-slate-900">Buchungsbestätigung</div>
                        <div class="text-sm text-slate-500 mt-0.5">Versand direkt nach einer Online-Buchung.</div>
                    </div>
                </div>

                <!-- Row -->
                <div class="flex items-center justify-between gap-6 py-1">
                    <div>
                        <div class="text-sm font-medium text-slate-900">Bestätigung an Eltern senden</div>
                        <div class="text-[13px] text-slate-500 mt-0.5">Per E-Mail mit allen Termindetails.</div>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="form.booking_confirmation_enabled"
                        @click="form.booking_confirmation_enabled = !form.booking_confirmation_enabled"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-150"
                        :class="form.booking_confirmation_enabled ? 'bg-kids-blue' : 'bg-slate-200'"
                    >
                        <span
                            class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform duration-150"
                            :class="form.booking_confirmation_enabled ? 'translate-x-6' : 'translate-x-1'"
                        />
                    </button>
                </div>
            </div>

            <!-- ── Section 3: Benachrichtigungen ans Team ───────────────────── -->
            <div class="bg-white rounded-ds-card border border-slate-200/70 shadow-card p-6">

                <!-- Card header -->
                <div class="flex items-start gap-3 mb-5">
                    <span class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[12px]"
                          style="background: color-mix(in srgb, var(--kids-blue) 15%, white); color: var(--kids-blue)">
                        <Bell class="h-5 w-5" :stroke-width="1.75" />
                    </span>
                    <div class="flex-1">
                        <div class="text-lg font-bold text-slate-900">Benachrichtigungen ans Team</div>
                        <div class="text-sm text-slate-500 mt-0.5">Wann das Praxisteam informiert wird.</div>
                    </div>
                </div>

                <div class="grid gap-1">
                    <!-- Row 1 -->
                    <div class="flex items-center justify-between gap-6 py-1">
                        <div>
                            <div class="text-sm font-medium text-slate-900">Neue Online-Buchung</div>
                            <div class="text-[13px] text-slate-500 mt-0.5">E-Mail an die Rezeption bei jeder Buchung.</div>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.notify_on_booking"
                            @click="form.notify_on_booking = !form.notify_on_booking"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-150"
                            :class="form.notify_on_booking ? 'bg-kids-blue' : 'bg-slate-200'"
                        >
                            <span
                                class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform duration-150"
                                :class="form.notify_on_booking ? 'translate-x-6' : 'translate-x-1'"
                            />
                        </button>
                    </div>

                    <!-- Divider -->
                    <div class="border-t border-slate-100 my-1" />

                    <!-- Row 2 -->
                    <div class="flex items-center justify-between gap-6 py-1">
                        <div>
                            <div class="text-sm font-medium text-slate-900">Stornierung durch Eltern</div>
                            <div class="text-[13px] text-slate-500 mt-0.5">E-Mail, wenn ein Termin abgesagt wird.</div>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="form.notify_on_cancellation"
                            @click="form.notify_on_cancellation = !form.notify_on_cancellation"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-150"
                            :class="form.notify_on_cancellation ? 'bg-kids-blue' : 'bg-slate-200'"
                        >
                            <span
                                class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform duration-150"
                                :class="form.notify_on_cancellation ? 'translate-x-6' : 'translate-x-1'"
                            />
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>
