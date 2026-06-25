<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import {
    LayoutDashboard, CalendarDays, ListChecks, Stethoscope, ClipboardList,
    Clock, TreePalm, Palette, QrCode, ShieldCheck, LogOut, ChartColumn, Hourglass,
    Settings,
} from 'lucide-vue-next'
import ToastNotification from '@/components/ui/ToastNotification.vue'
import { useToast } from '@/composables/useToast'

const { show: showToast } = useToast()

const page = usePage()
const tenantName = computed(() => (page.props as any).app_name ?? 'KidsClub')
const user = computed(() => (page.props as any).auth?.user)
const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)
const pendingCount = computed(() => (page.props as any).waitlist_pending_count as number ?? 0)

watch(flashSuccess, (msg) => { if (msg) showToast(msg) }, { immediate: true })

const roleLabel = computed(() => {
    const u = user.value
    if (!u) return ''
    if (u.role === 'medecin') return u.practitioner?.name ?? 'Behandler'
    return 'Admin'
})

const logout = () => router.post('/logout')

interface NavItem { href: string; label: string; icon: any }
interface NavGroup { label?: string; items: NavItem[] }

const navGroups: NavGroup[] = [
    {
        items: [
            { href: '/dashboard',     label: 'Dashboard',   icon: LayoutDashboard },
            { href: '/termine',       label: 'Termine',     icon: CalendarDays },
            { href: '/termine/liste',    label: 'Terminliste',    icon: ListChecks },
{ href: '/warteliste',       label: 'Warteliste',     icon: Hourglass },
            { href: '/statistiken',   label: 'Statistik',   icon: ChartColumn },
        ],
    },
    {
        label: 'Verwaltung',
        items: [
            { href: '/behandler',    label: 'Behandler',    icon: Stethoscope },
            { href: '/leistungen',   label: 'Leistungen',   icon: ClipboardList },
            { href: '/sprechzeiten', label: 'Sprechzeiten', icon: Clock },
            { href: '/abwesenheiten', label: 'Abwesenheiten', icon: TreePalm },
        ],
    },
    {
        label: 'Konfiguration',
        items: [
            { href: '/einstellungen',    label: 'Einstellungen',   icon: Settings },
            { href: '/erscheinungsbild', label: 'Erscheinungsbild', icon: Palette },
            { href: '/termin-qr-code',   label: 'QR-Code',         icon: QrCode },
            { href: '/sicherheit',       label: 'Sicherheit',       icon: ShieldCheck },
        ],
    },
]

const allNavItems = navGroups.flatMap((g) => g.items)
const currentUrl = computed(() => page.url)

// Longest-prefix match so /termine doesn't light up on /termine/liste.
const isActive = (href: string) => {
    const url = (currentUrl.value ?? '').split('?')[0].split('#')[0]
    const matches = (h: string) => url === h || url.startsWith(h + '/')
    if (!matches(href)) return false
    const longestMatch = allNavItems
        .map((item) => item.href)
        .filter(matches)
        .reduce((a, b) => (b.length > a.length ? b : a), '')
    return href === longestMatch
}
</script>

<template>
    <div class="min-h-screen flex bg-slate-50">
        <aside class="w-64 bg-white border-r border-slate-100 flex flex-col">
            <!-- logo / wordmark -->
            <div class="px-6 pt-6 pb-4">
                <h2 class="text-lg font-bold tracking-tight" style="color: var(--kids-blue)">{{ tenantName }}</h2>
            </div>

            <!-- navigation -->
            <nav class="flex-1 overflow-y-auto px-3 pb-4 space-y-4">
                <div v-for="(group, gi) in navGroups" :key="gi">
                    <p v-if="group.label"
                       class="px-3 mb-1 text-[0.65rem] font-semibold uppercase tracking-widest text-slate-400 select-none">
                        {{ group.label }}
                    </p>
                    <div class="space-y-0.5">
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            class="flex items-center gap-3 px-3 py-2 rounded-ds-nav text-slate-600 hover:bg-[var(--kids-blue)]/20 transition-colors text-sm"
                            :class="isActive(item.href) ? 'bg-[var(--kids-blue)]/20 text-slate-800 font-medium' : ''"
                        >
                            <component :is="item.icon" class="h-4 w-4 shrink-0" :stroke-width="1.75" />
                            <span class="truncate">{{ item.label }}</span>
                            <span
                                v-if="item.href === '/warteliste' && pendingCount > 0"
                                class="ml-auto text-[0.65rem] font-bold bg-rose-500 text-white rounded-full px-1.5 py-0.5 leading-none"
                            >{{ pendingCount }}</span>
                        </Link>
                    </div>
                </div>
            </nav>

            <!-- user footer -->
            <div class="border-t border-slate-100 px-6 py-4">
                <div class="text-sm font-medium text-slate-700 truncate">{{ roleLabel }}</div>
                <div class="text-xs text-slate-400 mb-2 truncate">{{ user?.email }}</div>
                <button @click="logout" class="flex items-center gap-1.5 text-sm text-rose-500 hover:text-rose-600 transition-colors">
                    <LogOut class="h-4 w-4" :stroke-width="1.75" /> Abmelden
                </button>
            </div>
        </aside>

        <main class="flex-1 min-w-0">
            <slot />
        </main>
    </div>
    <ToastNotification />
</template>
