import { ref } from 'vue'

interface ToastMessage {
    id: number
    text: string
}

const messages = ref<ToastMessage[]>([])
let nextId = 0

export function useToast() {
    function show(text: string, duration = 2800) {
        const id = ++nextId
        messages.value.push({ id, text })
        setTimeout(() => dismiss(id), duration)
    }

    function dismiss(id: number) {
        const idx = messages.value.findIndex((m) => m.id === id)
        if (idx !== -1) messages.value.splice(idx, 1)
    }

    return { messages, show, dismiss }
}
