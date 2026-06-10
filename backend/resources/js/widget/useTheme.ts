import { reactive } from 'vue'
import type { Api } from './api'
import type { WidgetConfig, WidgetTheme } from './types'

export const DEFAULT_THEME: WidgetTheme = {
    colorPrimary: '#6B8FA3',
    colorPrimaryTo: '#C40C78',
    colorAccent: '#EC0A8C',
    colorBackground: '#FFFFFF',
    colorText: '#26257F',
    fontHeading: 'Fredoka',
    fontBody: 'Nunito',
    radius: '26px',
}

/**
 * Curated font allow-list. @font-face does not reliably apply inside Shadow
 * DOM, so faces are loaded document-wide via a <link> in document.head; the
 * family is then usable inside the shadow tree. 'System' loads nothing.
 */
const FONT_SOURCES: Record<string, string | null> = {
    Fredoka: 'https://fonts.googleapis.com/css2?family=Fredoka:wght@400;500;600;700&display=swap',
    Nunito: 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap',
    Inter: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
    Poppins: 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap',
    System: null,
}

export function hexToRgbTriplet(hex: string): string {
    const h = hex.replace('#', '')
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)).join(' ')
}

function fontStack(name: string): string {
    return name === 'System' ? 'system-ui, -apple-system, sans-serif' : `'${name}', system-ui, sans-serif`
}

function ensureFontLoaded(name: string) {
    const href = FONT_SOURCES[name]
    if (!href) return
    const id = `masinga-font-${name.toLowerCase()}`
    if (document.getElementById(id)) return
    const link = document.createElement('link')
    link.id = id
    link.rel = 'stylesheet'
    link.href = href
    document.head.appendChild(link)
}

/**
 * Single source of truth for the dual-var contract: every color sets BOTH the
 * hex var (gradients, inline styles) and the SPACE-separated rgb triplet var
 * (Tailwind alpha modifiers). Never set one without the other.
 */
export function applyTheme(el: HTMLElement, theme: WidgetTheme) {
    const set = (k: string, v: string) => el.style.setProperty(k, v)
    const color = (name: string, hex: string) => {
        set(`--masinga-${name}`, hex)
        set(`--masinga-${name}-rgb`, hexToRgbTriplet(hex))
    }
    color('primary', theme.colorPrimary)
    color('primary-to', theme.colorPrimaryTo)
    color('accent', theme.colorAccent)
    color('bg', theme.colorBackground)
    color('text', theme.colorText)
    set('--masinga-radius', theme.radius)
    set('--masinga-font-heading', fontStack(theme.fontHeading))
    set('--masinga-font-body', fontStack(theme.fontBody))
}

export function useTheme(api: Pick<Api, 'config'>) {
    const state = reactive<{ config: WidgetConfig | null }>({ config: null })

    async function load(el: HTMLElement) {
        try {
            const cfg = await api.config()
            state.config = cfg
            const theme = { ...DEFAULT_THEME, ...cfg.theme }
            applyTheme(el, theme)
            ensureFontLoaded(theme.fontHeading)
            ensureFontLoaded(theme.fontBody)
        } catch {
            // CSS :host defaults already painted — a failed config fetch
            // must never affect the booking flow.
        }
    }

    return { state, load }
}
