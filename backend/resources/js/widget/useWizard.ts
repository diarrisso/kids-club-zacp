import { ref, reactive } from 'vue'
import type { Service, Slot } from './types'

export type Step = 'termin' | 'kind' | 'form' | 'confirm' | 'success' | 'waitlist'
const ORDER: Step[] = ['termin', 'kind', 'form', 'confirm', 'success']

export function useWizard() {
    const step = ref<Step>('termin')
    const selection = reactive<{ service?: Service; slot?: Slot }>({})

    const go = (s: Step) => { step.value = s }

    return {
        step,
        selection,
        go,
        // Choosing a service no longer advances — the calendar appears in-place on the termin step.
        chooseService(s: Service) { selection.service = s },
        // Selecting a slot records it but stays on the termin step — the user
        // confirms with an explicit "Weiter" (confirmSlot) and can change their
        // mind freely before that.
        chooseSlot(slot: Slot) { selection.slot = slot },
        confirmSlot() { if (selection.slot) go('kind') },
        clearSlot() { selection.slot = undefined },
        advance() {
            const i = ORDER.indexOf(step.value)
            if (i >= 0 && i < ORDER.length - 1) go(ORDER[i + 1])
        },
        backToTermin() { go('termin') },
        complete() { go('success') },
        reset() {
            selection.service = undefined
            selection.slot = undefined
            go('termin')
        },
        back() {
            const i = ORDER.indexOf(step.value)
            if (i > 0) go(ORDER[i - 1])
        },
    }
}
