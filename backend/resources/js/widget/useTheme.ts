import { reactive } from 'vue'
import type { InjectionKey } from 'vue'
import type { Api } from './api'
import type { WidgetConfig, WidgetTheme } from './types'

export const WIDGET_CONFIG_KEY: InjectionKey<{ config: WidgetConfig | null }> = Symbol('widgetConfig')

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
 * Self-hosted font faces (GDPR: never Google Fonts — visitor IPs must not
 * leak to third parties from embedding practice sites). Each entry yields
 * @font-face CSS injected document-wide (faces don't apply inside Shadow DOM).
 */
const FONT_FACES: Record<string, (base: string) => string> = Object.assign(Object.create(null), {
    Fredoka: (b: string) => face('Fredoka', `${b}/api/v1/widget/fonts/fredoka.woff2`, '300 700'),
    Nunito: (b: string) => face('Nunito', `${b}/api/v1/widget/fonts/nunito.woff2`, '300 800'),
    Inter: (b: string) => face('Inter', `${b}/api/v1/widget/fonts/inter.woff2`, '100 900'),
    Poppins: (b: string) => ['400', '600', '700']
        .map(w => face('Poppins', `${b}/api/v1/widget/fonts/poppins-${w}.woff2`, w)).join('\n'),
    System: () => '',
})

function face(family: string, url: string, weight: string): string {
    return `@font-face{font-family:'${family}';src:url('${url}') format('woff2');font-weight:${weight};font-display:swap;}`
}

export function hexToRgbTriplet(hex: string): string {
    const h = hex.replace('#', '')
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)).join(' ')
}

/** Unknown families (not in the allow-list) degrade to the system stack —
 *  config values never reach CSS as arbitrary strings. */
function fontStack(name: string): string {
    if (name === 'System' || !Object.hasOwn(FONT_FACES, name)) return 'system-ui, -apple-system, sans-serif'
    return `'${name}', system-ui, sans-serif`
}

function ensureFontLoaded(name: string, base: string) {
    if (!Object.hasOwn(FONT_FACES, name)) return
    const css = FONT_FACES[name](base.replace(/\/$/, ''))
    if (!css) return
    const id = `masinga-font-${name.toLowerCase()}`
    if (document.getElementById(id)) return
    const style = document.createElement('style')
    style.id = id
    style.textContent = css
    document.head.appendChild(style)
}

/**
 * Single source of truth for the dual-var contract: every color sets BOTH the
 * hex var (gradients, inline styles) and the SPACE-separated rgb triplet var
 * (Tailwind alpha modifiers). Never set one without the other. Invalid hex
 * input falls back to the DEFAULT_THEME value for that token.
 */
export function applyTheme(el: HTMLElement, theme: WidgetTheme) {
    const set = (k: string, v: string) => el.style.setProperty(k, v)
    const color = (name: string, hex: string, fallback: string) => {
        const safe = /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : fallback
        set(`--masinga-${name}`, safe)
        set(`--masinga-${name}-rgb`, hexToRgbTriplet(safe))
    }
    color('primary', theme.colorPrimary, DEFAULT_THEME.colorPrimary)
    color('primary-to', theme.colorPrimaryTo, DEFAULT_THEME.colorPrimaryTo)
    color('accent', theme.colorAccent, DEFAULT_THEME.colorAccent)
    color('bg', theme.colorBackground, DEFAULT_THEME.colorBackground)
    color('text', theme.colorText, DEFAULT_THEME.colorText)
    set('--masinga-radius', theme.radius)
    set('--masinga-font-heading', fontStack(theme.fontHeading))
    set('--masinga-font-body', fontStack(theme.fontBody))
}

/**
 * Derived tokens (--masinga-gradient, tints) are declared on :host and
 * resolve their var() references THERE — so runtime overrides must land on
 * the shadow host element, not an inner node. Falls back to the element
 * itself outside a shadow tree (unit tests mount without one).
 */
export function themeTargetFor(el: HTMLElement): HTMLElement {
    const root = el.getRootNode()
    return root instanceof ShadowRoot ? (root.host as HTMLElement) : el
}

export function useTheme(api: Pick<Api, 'config'>, apiBase = '') {
    const state = reactive<{ config: WidgetConfig | null }>({ config: null })

    async function load(el: HTMLElement) {
        try {
            const cfg = await api.config()
            state.config = cfg
            const theme = { ...DEFAULT_THEME, ...cfg.theme }
            applyTheme(themeTargetFor(el), theme)
            ensureFontLoaded(theme.fontHeading, apiBase)
            ensureFontLoaded(theme.fontBody, apiBase)
        } catch (e) {
            // CSS :host defaults already painted — a failed config fetch
            // must never affect the booking flow.
            if (import.meta.env.DEV) console.warn('[masinga] theme load failed', e)
        }
    }

    return { state, load }
}
