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
            // Undo the most recent selection, then return to the step of the
            // latest remaining selection (or the first step if none remain).
            if (selection.slot) {
                selection.slot = undefined
                go('practitioner')
            } else if (selection.practitioner) {
                selection.practitioner = undefined
                go('service')
            } else if (selection.service) {
                selection.service = undefined
                go('service')
            } else {
                go('service')
            }
        },
    }
}
