import { createApp } from 'vue'
import App from './App.vue'
import { createApi } from './api'
import widgetCss from './widget.css?inline'

export function widgetVersion(): string {
    return 'phase-3'
}

function mountWidget(el: HTMLElement): boolean {
    const apiBase = el.dataset.api ?? ''
    if (!apiBase) {
        console.error('[masinga] data-api is required')
        return false
    }
    const shadow = el.attachShadow({ mode: 'open' })
    const style = document.createElement('style')
    style.textContent = widgetCss
    shadow.appendChild(style)
    const container = document.createElement('div')
    shadow.appendChild(container)
    createApp(App, { api: createApi(apiBase), apiBase }).mount(container)
    return true
}

function boot() {
    document.querySelectorAll<HTMLElement>('[data-masinga-booking]').forEach((el) => {
        if (!el.dataset.masingaMounted) {
            if (mountWidget(el)) {
                el.dataset.masingaMounted = '1'
            }
        }
    })
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot)
} else {
    boot()
}
