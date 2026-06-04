import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import RoomLegend from '@/components/ui/RoomLegend.vue'

const rooms = [
  { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
  { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
]

describe('RoomLegend', () => {
  it('renders a label per room', () => {
    const wrapper = mount(RoomLegend, { props: { rooms } })
    expect(wrapper.text()).toContain('Grünes Zimmer')
    expect(wrapper.text()).toContain('Blaues Zimmer')
  })
})
