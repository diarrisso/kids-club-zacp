import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import RoomPicker from '@/components/ui/RoomPicker.vue'

const rooms = [
  { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
  { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
]

describe('RoomPicker', () => {
  it('renders one swatch per room', () => {
    const wrapper = mount(RoomPicker, { props: { rooms, modelValue: null } })
    expect(wrapper.findAll('button[data-room]')).toHaveLength(2)
  })

  it('emits the room value when a swatch is clicked', async () => {
    const wrapper = mount(RoomPicker, { props: { rooms, modelValue: null } })
    await wrapper.find('button[data-room="blue"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['blue'])
  })

  it('toggles selection off when the active swatch is clicked again (optional)', async () => {
    const wrapper = mount(RoomPicker, { props: { rooms, modelValue: 'blue' } })
    await wrapper.find('button[data-room="blue"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null])
  })
})
