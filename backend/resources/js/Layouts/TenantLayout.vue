<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()
const tenantName = computed(() => (page.props as any).app_name ?? 'Cabinet')
const user = computed(() => (page.props as any).auth?.user)

const logout = () => router.post('/logout')

const nav = [
    { href: '/dashboard', label: '📅 Dashboard' },
    { href: '/termine', label: '🗓️ Termine' },
    { href: '/behandler', label: '👨‍⚕️ Behandler' },
    { href: '/leistungen', label: '🦷 Leistungen' },
    { href: '/sprechzeiten', label: '⏰ Sprechzeiten' },
    { href: '/abwesenheiten', label: '🏖️ Abwesenheiten' },
    { href: '/termin-qr-code', label: '🔳 QR-Code' },
]
</script>

<template>
    <div class="min-h-screen flex bg-slate-50">
        <aside class="w-64 bg-white border-r p-6 flex flex-col">
            <h2 class="text-xl font-bold text-blue-700 mb-8">{{ tenantName }}</h2>
            <nav class="space-y-1 flex-1">
                <Link v-for="item in nav" :key="item.href" :href="item.href"
                      class="block px-3 py-2 rounded hover:bg-blue-50">
                    {{ item.label }}
                </Link>
            </nav>
            <div class="mt-6">
                <div class="text-sm text-slate-600 mb-2">{{ user?.email }}</div>
                <button @click="logout" class="text-sm text-red-600 hover:underline">Abmelden</button>
            </div>
        </aside>
        <main class="flex-1"><slot /></main>
    </div>
</template>
