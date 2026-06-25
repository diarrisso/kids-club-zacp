<script setup lang="ts">
import { useToast } from '@/composables/useToast'

const { messages, dismiss } = useToast()
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex flex-col items-center gap-2 pointer-events-none"
            aria-live="polite"
        >
            <TransitionGroup
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 translate-y-3 scale-95"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="transition-all duration-200 ease-in"
                leave-from-class="opacity-100 translate-y-0 scale-100"
                leave-to-class="opacity-0 translate-y-2 scale-95"
            >
                <div
                    v-for="msg in messages"
                    :key="msg.id"
                    class="pointer-events-auto flex items-center gap-2.5 bg-slate-900 text-white text-sm font-medium px-4 py-2.5 rounded-full shadow-lg"
                >
                    <svg class="w-4 h-4 text-green-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    <span>{{ msg.text }}</span>
                    <button
                        type="button"
                        class="ml-1 text-slate-400 hover:text-white transition-colors"
                        @click="dismiss(msg.id)"
                        aria-label="Schließen"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
