<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import type { Service } from '../types'

const props = defineProps<{ services: Service[]; modelValue?: Service }>()
const emit = defineEmits<{ select: [service: Service] }>()

const open = ref(false)
const highlighted = ref(0)
const listEl = ref<HTMLElement | null>(null)
const triggerEl = ref<HTMLElement | null>(null)

const label = computed(() =>
    props.modelValue ? `${props.modelValue.name}` : 'Leistung wählen',
)

async function toggle(toOpen = !open.value) {
    open.value = toOpen
    if (toOpen) {
        highlighted.value = Math.max(0, props.services.findIndex(s => s.id === props.modelValue?.id))
        await nextTick()
        listEl.value?.focus()
    }
}

function choose(s: Service) {
    emit('select', s)
    open.value = false
    triggerEl.value?.focus()
}

function onTriggerKeydown(e: KeyboardEvent) {
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        toggle(true)
    }
}

function onListKeydown(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); highlighted.value = Math.min(highlighted.value + 1, props.services.length - 1) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted.value = Math.max(highlighted.value - 1, 0) }
    else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(props.services[highlighted.value]) }
    else if (e.key === 'Escape') { e.preventDefault(); open.value = false; triggerEl.value?.focus() }
}
</script>

<template>
    <div class="relative">
        <button ref="triggerEl" type="button" data-service-trigger
                :aria-expanded="open ? 'true' : 'false'" aria-haspopup="listbox"
                @click="toggle()" @keydown="onTriggerKeydown"
                class="flex w-full items-center justify-between rounded-2xl border border-slate-200 bg-widget-bg px-4 py-3.5 text-sm shadow-sm transition-all duration-200 hover:border-accent/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50 focus-visible:ring-offset-2">
            <span class="flex items-center gap-3 min-w-0">
                <span v-if="modelValue" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl"
                      :style="{ backgroundColor: (modelValue.color || '#FBB9C4') + '28' }" aria-hidden="true">
                    <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: modelValue.color || '#FBB9C4' }"></span>
                </span>
                <span class="truncate font-semibold" :class="modelValue ? 'text-widget-text' : 'text-slate-400'">{{ label }}</span>
            </span>
            <span class="flex items-center gap-2 shrink-0 ml-2">
                <span v-if="modelValue" class="inline-flex items-center rounded-full bg-tint px-2.5 py-1 text-[11px] font-semibold text-widget-text/70">
                    {{ modelValue.duration_minutes }} Min.
                </span>
                <svg class="h-4 w-4 text-slate-400 transition-transform" :class="open ? 'rotate-180' : ''"
                     viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </button>

        <ul v-if="open" ref="listEl" role="listbox" tabindex="-1" aria-label="Leistung"
            :aria-activedescendant="`masinga-service-opt-${highlighted}`"
            @keydown="onListKeydown"
            class="absolute z-20 mt-2 w-full overflow-hidden rounded-2xl border border-slate-100 bg-widget-bg shadow-xl focus:outline-none">
            <li v-for="(s, i) in services" :key="s.id" role="option"
                :id="`masinga-service-opt-${i}`"
                data-service :data-service-id="s.id"
                :aria-selected="modelValue?.id === s.id ? 'true' : 'false'"
                @click="choose(s)" @mousemove="highlighted = i"
                class="flex cursor-pointer items-center justify-between px-4 py-3 text-sm transition-colors"
                :class="i === highlighted ? 'bg-tint' : ''">
                <span class="flex items-center gap-3 min-w-0">
                    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg"
                          :style="{ backgroundColor: (s.color || '#FBB9C4') + '28' }" aria-hidden="true">
                        <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: s.color || '#FBB9C4' }"></span>
                    </span>
                    <span class="truncate font-semibold text-widget-text">{{ s.name }}</span>
                </span>
                <span class="ml-2 shrink-0 rounded-full bg-tint px-2.5 py-1 text-[11px] font-semibold text-widget-text/70">
                    {{ s.duration_minutes }} Min.
                </span>
            </li>
        </ul>
    </div>
</template>
