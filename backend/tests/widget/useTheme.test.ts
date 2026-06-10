import { describe, it, expect, vi, beforeEach } from 'vitest'
import { applyTheme, hexToRgbTriplet, useTheme, themeTargetFor, DEFAULT_THEME } from '@widget/useTheme'
import type { WidgetConfig } from '@widget/types'

const BASE = 'https://backend.test'

const cfg: WidgetConfig = {
    theme: { ...DEFAULT_THEME, colorPrimary: '#112233', colorAccent: '#445566', radius: '8px' },
    logoUrl: 'https://x.test/storage/widget/logo.png',
    datenschutzUrl: 'https://praxis.test/datenschutz',
    impressumUrl: null,
}

beforeEach(() => {
    // Font <style> injection is document-wide and idempotent by id — reset
    // between tests so each case observes its own injection.
    document.head.querySelectorAll('[id^="masinga-font-"]').forEach((n) => n.remove())
})

describe('hexToRgbTriplet', () => {
    it('converts #RRGGBB to a SPACE-separated triplet', () => {
        expect(hexToRgbTriplet('#112233')).toBe('17 34 51')
        expect(hexToRgbTriplet('#FFFFFF')).toBe('255 255 255')
    })
})

describe('applyTheme', () => {
    it('derives hex and rgb vars from the SAME color, plus radius and fonts', () => {
        const el = document.createElement('div')
        applyTheme(el, cfg.theme)
        expect(el.style.getPropertyValue('--masinga-primary')).toBe('#112233')
        expect(el.style.getPropertyValue('--masinga-primary-rgb')).toBe('17 34 51')
        expect(el.style.getPropertyValue('--masinga-accent')).toBe('#445566')
        expect(el.style.getPropertyValue('--masinga-accent-rgb')).toBe('68 85 102')
        expect(el.style.getPropertyValue('--masinga-radius')).toBe('8px')
        expect(el.style.getPropertyValue('--masinga-font-heading')).toContain('Fredoka')
    })

    it('sets every color token pair (no split-brain)', () => {
        const el = document.createElement('div')
        applyTheme(el, DEFAULT_THEME)
        for (const name of ['primary', 'primary-to', 'accent', 'bg', 'text']) {
            expect(el.style.getPropertyValue(`--masinga-${name}`), name).toMatch(/^#/)
            expect(el.style.getPropertyValue(`--masinga-${name}-rgb`), name).toMatch(/^\d+ \d+ \d+$/)
        }
    })

    it('falls back to the default color on invalid hex (both vars, no NaN)', () => {
        const el = document.createElement('div')
        applyTheme(el, { ...DEFAULT_THEME, colorPrimary: '#FFF' })
        expect(el.style.getPropertyValue('--masinga-primary')).toBe(DEFAULT_THEME.colorPrimary)
        expect(el.style.getPropertyValue('--masinga-primary-rgb')).toBe(hexToRgbTriplet(DEFAULT_THEME.colorPrimary))
        expect(el.style.getPropertyValue('--masinga-primary-rgb')).not.toContain('NaN')
    })

    it('treats an unknown font family as System (no arbitrary injection into font stacks)', () => {
        const el = document.createElement('div')
        applyTheme(el, { ...DEFAULT_THEME, fontHeading: 'Evil"; }</style>' })
        expect(el.style.getPropertyValue('--masinga-font-heading')).toContain('system-ui')
        expect(el.style.getPropertyValue('--masinga-font-heading')).not.toContain('Evil')
    })
})

describe('themeTargetFor', () => {
    it('returns the shadow host for elements inside a shadow tree', () => {
        const host = document.createElement('div')
        const shadow = host.attachShadow({ mode: 'open' })
        const inner = document.createElement('div')
        shadow.appendChild(inner)
        expect(themeTargetFor(inner)).toBe(host)
    })

    it('returns the element itself outside a shadow tree', () => {
        const el = document.createElement('div')
        expect(themeTargetFor(el)).toBe(el)
    })
})

describe('useTheme', () => {
    it('loads the config, applies it, and exposes the urls', async () => {
        const api = { config: vi.fn().mockResolvedValue(cfg) }
        const el = document.createElement('div')
        const t = useTheme(api as any, BASE)
        await t.load(el)
        expect(el.style.getPropertyValue('--masinga-primary')).toBe('#112233')
        expect(t.state.config?.datenschutzUrl).toBe('https://praxis.test/datenschutz')
        expect(t.state.config?.logoUrl).toContain('logo.png')
    })

    it('keeps the CSS defaults silently when the fetch fails', async () => {
        const api = { config: vi.fn().mockRejectedValue({ kind: 'network' }) }
        const el = document.createElement('div')
        const t = useTheme(api as any, BASE)
        await t.load(el) // must not throw
        expect(el.style.getPropertyValue('--masinga-primary')).toBe('') // untouched → :host defaults rule
        expect(t.state.config).toBeNull()
    })

    it('applies the vars on the shadow HOST so :host-derived tokens re-derive', async () => {
        const host = document.createElement('div')
        const shadow = host.attachShadow({ mode: 'open' })
        const inner = document.createElement('div')
        shadow.appendChild(inner)
        const api = { config: vi.fn().mockResolvedValue(cfg) }
        const t = useTheme(api as any, BASE)
        await t.load(inner)
        expect(host.style.getPropertyValue('--masinga-primary')).toBe('#112233')
        expect(inner.style.getPropertyValue('--masinga-primary')).toBe('')
    })

    it('injects self-hosted @font-face styles — never Google Fonts', async () => {
        const api = { config: vi.fn().mockResolvedValue(cfg) } // fontHeading Fredoka, fontBody Nunito
        const t = useTheme(api as any, BASE)
        await t.load(document.createElement('div'))
        const style = document.getElementById('masinga-font-fredoka')
        expect(style).not.toBeNull()
        expect(style!.tagName).toBe('STYLE')
        expect(style!.textContent).toContain('@font-face')
        expect(style!.textContent).toContain(`${BASE}/api/v1/widget/fonts/`)
        expect(style!.textContent).not.toContain('googleapis')
        expect(document.getElementById('masinga-font-nunito')).not.toBeNull()
    })

    it('does not duplicate the font styles when load runs twice', async () => {
        const api = { config: vi.fn().mockResolvedValue(cfg) }
        const t = useTheme(api as any, BASE)
        const el = document.createElement('div')
        await t.load(el)
        await t.load(el)
        expect(document.querySelectorAll('#masinga-font-fredoka')).toHaveLength(1)
    })

    it('injects nothing for the System font', async () => {
        const api = {
            config: vi.fn().mockResolvedValue({
                ...cfg,
                theme: { ...cfg.theme, fontHeading: 'System', fontBody: 'System' },
            }),
        }
        const t = useTheme(api as any, BASE)
        await t.load(document.createElement('div'))
        expect(document.head.querySelectorAll('[id^="masinga-font-"]')).toHaveLength(0)
    })
})
