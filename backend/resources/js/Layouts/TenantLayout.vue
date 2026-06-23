<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
    LayoutDashboard, CalendarDays, ListChecks, Stethoscope, ClipboardList,
    Clock, TreePalm, Palette, QrCode, ShieldCheck, LogOut, ChartColumn,
} from 'lucide-vue-next'

const page = usePage()
const tenantName = computed(() => (page.props as any).app_name ?? 'KidsClub')
const user = computed(() => (page.props as any).auth?.user)

const roleLabel = computed(() => {
    const u = user.value
    if (!u) return ''
    if (u.role === 'medecin') return u.practitioner?.name ?? 'Behandler'
    return 'Réception'
})

const logout = () => router.post('/logout')

const nav = [
    { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { href: '/termine', label: 'Termine', icon: CalendarDays },
    { href: '/termine/liste', label: 'Terminliste', icon: ListChecks },
    { href: '/statistiken', label: 'Statistiken', icon: ChartColumn },
    { href: '/behandler', label: 'Behandler', icon: Stethoscope },
    { href: '/leistungen', label: 'Leistungen', icon: ClipboardList },
    { href: '/sprechzeiten', label: 'Sprechzeiten', icon: Clock },
    { href: '/abwesenheiten', label: 'Abwesenheiten', icon: TreePalm },
    { href: '/erscheinungsbild', label: 'Erscheinungsbild', icon: Palette },
    { href: '/termin-qr-code', label: 'QR-Code', icon: QrCode },
    { href: '/sicherheit', label: 'Sicherheit', icon: ShieldCheck },
]

const currentUrl = computed(() => page.url)

// Longest-prefix match: among all nav hrefs that are a prefix of the current URL,
// only the longest one is active — prevents /termine from lighting up on /termine/liste.
const isActive = (href: string) => {
    // Strip query string and hash so e.g. /termine/liste?page=2 still matches.
    const url = (currentUrl.value ?? '').split('?')[0].split('#')[0]
    const matches = (h: string) => url === h || url.startsWith(h + '/')
    if (!matches(href)) return false
    const longestMatch = nav
        .map((item) => item.href)
        .filter(matches)
        .reduce((a, b) => (b.length > a.length ? b : a), '')
    return href === longestMatch
}
</script>

<template>
    <div class="min-h-screen flex bg-slate-50">
        <aside class="w-64 bg-white border-r border-slate-100 p-6 flex flex-col">
            <h2 class="text-xl font-bold mb-8" style="color:#7d93a3">{{ tenantName }}</h2>
            <nav class="space-y-1 flex-1">
                <Link v-for="item in nav" :key="item.href" :href="item.href"
                      class="flex items-center gap-3 px-3 py-2 rounded-xl text-slate-600 hover:bg-kids-blue/20 transition"
                      :class="isActive(item.href) ? 'bg-kids-blue/20 text-slate-800 font-medium' : ''">
                    <component :is="item.icon" class="h-5 w-5" :stroke-width="1.75" />
                    {{ item.label }}
                </Link>
            </nav>
            <div class="mt-6">
                <div class="text-sm font-medium text-slate-700">{{ roleLabel }}</div>
                <div class="text-xs text-slate-500 mb-2">{{ user?.email }}</div>
                <button @click="logout" class="flex items-center gap-1 text-sm text-red-600 hover:underline">
                    <LogOut class="h-4 w-4" /> Abmelden
                </button>
            </div>
        </aside>
        <main class="flex-1"><slot /></main>
    </div>
</template>
