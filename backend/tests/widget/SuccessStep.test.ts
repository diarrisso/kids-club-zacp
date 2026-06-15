import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import SuccessStep from '@widget/steps/SuccessStep.vue'

const result = { reference: 'KC-524E50', cancellation_token: 'tok', starts_at: '2026-06-10T10:30:00+02:00', ends_at: '2026-06-10T11:15:00+02:00' }

describe('SuccessStep auto-close', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('shows a visible countdown that ticks down', async () => {
    const w = mount(SuccessStep, { props: { result, cancelled: false } })
    expect(w.get('[data-autoclose-countdown]').text()).toContain('20')
    await vi.advanceTimersByTimeAsync(1000)
    expect(w.get('[data-autoclose-countdown]').text()).toContain('19')
  })

  it('emits close once the 20s countdown elapses', async () => {
    const w = mount(SuccessStep, { props: { result, cancelled: false } })
    expect(w.emitted('close')).toBeFalsy()
    await vi.advanceTimersByTimeAsync(20_000)
    expect(w.emitted('close')).toHaveLength(1)
  })

  it('lets the user close immediately via "Jetzt schließen"', async () => {
    const w = mount(SuccessStep, { props: { result, cancelled: false } })
    await w.get('[data-close-now]').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)
    // the timer must be cleared so it never emits a second close
    await vi.advanceTimersByTimeAsync(20_000)
    expect(w.emitted('close')).toHaveLength(1)
  })

  it('never auto-closes on the cancelled state', async () => {
    const w = mount(SuccessStep, { props: { result, cancelled: true } })
    expect(w.find('[data-autoclose-countdown]').exists()).toBe(false)
    await vi.advanceTimersByTimeAsync(20_000)
    expect(w.emitted('close')).toBeFalsy()
  })

  it('stops the auto-close when the user starts the cancellation flow', async () => {
    const w = mount(SuccessStep, { props: { result, cancelled: false } })
    await w.get('[data-cancel-open]').trigger('click') // user is interacting — do not yank the modal away
    await vi.advanceTimersByTimeAsync(20_000)
    expect(w.emitted('close')).toBeFalsy()
  })
})
