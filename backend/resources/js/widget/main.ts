import { createApp } from 'vue'
import App from './App.vue'
import { createApi } from './api'
import widgetCss from './widget.css?inline'

export function widgetVersion(): string {
    return 'phase-3'
}

function mountWidget(el: HTMLElement) {
    const tenant = el.dataset.tenant ?? ''
    const apiBase = el.dataset.api ?? ''
    if (!tenant || !apiBase) {
        console.error('[masinga] data-tenant and data-api are required')
        return
    }

    const shadow = el.attachShadow({ mode: 'open' })
    const style = document.createElement('style')
    style.textContent = widgetCss
    shadow.appendChild(style)

    const container = document.createElement('div')
    shadow.appendChild(container)

    createApp(App, { api: createApi(apiBase, tenant), apiBase, tenant }).mount(container)
}

function boot() {
    document.querySelectorAll<HTMLElement>('[data-masinga-booking]').forEach((el) => {
        if (!el.dataset.masingaMounted) {
            el.dataset.masingaMounted = '1'
            mountWidget(el)
        }
    })
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot)
} else {
    boot()
}
