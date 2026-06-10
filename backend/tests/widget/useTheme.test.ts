import { describe, it, expect, vi } from 'vitest'
import { applyTheme, hexToRgbTriplet, useTheme, DEFAULT_THEME } from '@widget/useTheme'
import type { WidgetConfig } from '@widget/types'

const cfg: WidgetConfig = {
    theme: { ...DEFAULT_THEME, colorPrimary: '#112233', colorAccent: '#445566', radius: '8px' },
    logoUrl: 'https://x.test/storage/widget/logo.png',
    datenschutzUrl: 'https://praxis.test/datenschutz',
    impressumUrl: null,
}

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
})

describe('useTheme', () => {
    it('loads the config, applies it, and exposes the urls', async () => {
        const api = { config: vi.fn().mockResolvedValue(cfg) }
        const el = document.createElement('div')
        const t = useTheme(api as any)
        await t.load(el)
        expect(el.style.getPropertyValue('--masinga-primary')).toBe('#112233')
        expect(t.state.config?.datenschutzUrl).toBe('https://praxis.test/datenschutz')
        expect(t.state.config?.logoUrl).toContain('logo.png')
    })

    it('keeps the CSS defaults silently when the fetch fails', async () => {
        const api = { config: vi.fn().mockRejectedValue({ kind: 'network' }) }
        const el = document.createElement('div')
        const t = useTheme(api as any)
        await t.load(el) // must not throw
        expect(el.style.getPropertyValue('--masinga-primary')).toBe('') // untouched → :host defaults rule
        expect(t.state.config).toBeNull()
    })
})
