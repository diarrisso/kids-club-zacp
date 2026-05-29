import { describe, it, expect } from 'vitest'
import { widgetVersion } from '@widget/main'

describe('widget tooling', () => {
    it('exposes a version constant', () => {
        expect(widgetVersion()).toBe('phase-3')
    })
})
