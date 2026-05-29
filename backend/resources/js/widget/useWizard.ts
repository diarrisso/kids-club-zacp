import { ref, reactive } from 'vue'
import type { Service, Practitioner, Slot } from './types'

export type Step = 'service' | 'practitioner' | 'slot' | 'form' | 'success'
const ORDER: Step[] = ['service', 'practitioner', 'slot', 'form', 'success']

export function useWizard() {
    const step = ref<Step>('service')
    const selection = reactive<{ service?: Service; practitioner?: Practitioner; slot?: Slot }>({})

    const go = (s: Step) => { step.value = s }

    return {
        step,
        selection,
        chooseService(s: Service) { selection.service = s; go('practitioner') },
        choosePractitioner(p: Practitioner) { selection.practitioner = p; go('slot') },
        chooseSlot(slot: Slot) { selection.slot = slot; go('form') },
        complete() { go('success') },
        back() {
            // Linear back: return to the previous step, keeping earlier
            // selections (re-choosing at an earlier step overwrites + re-advances).
            const i = ORDER.indexOf(step.value)
            if (i > 0) go(ORDER[i - 1])
        },
    }
}
