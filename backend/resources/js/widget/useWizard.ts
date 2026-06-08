import { ref, reactive } from 'vue'
import type { Service, Slot } from './types'

export type Step = 'service' | 'termin' | 'form' | 'success'
const ORDER: Step[] = ['service', 'termin', 'form', 'success']

export function useWizard() {
    const step = ref<Step>('service')
    const selection = reactive<{ service?: Service; slot?: Slot }>({})

    const go = (s: Step) => { step.value = s }

    return {
        step,
        selection,
        chooseService(s: Service) { selection.service = s; go('termin') },
        chooseSlot(slot: Slot) { selection.slot = slot; go('form') },
        complete() { go('success') },
        back() {
            // Linear back: the practitioner is now carried by the chosen slot,
            // so the flow is service → termin → form → success.
            const i = ORDER.indexOf(step.value)
            if (i > 0) go(ORDER[i - 1])
        },
    }
}
