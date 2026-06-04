// Front-end mirror of the PHP source of truth `App\Support\Room`
// (app/Support/Room.php). The five KidsClub rooms and their pastel colors live
// here once for the main app. Staff pages that receive `Room::options()` as an
// Inertia prop should prefer that prop; this module serves the pure helpers and
// the components/pages that don't get the prop. The standalone widget bundle is
// built separately and inlines its own copy by design.
export interface RoomOption {
    value: string
    color: string
    label: string
}

export const ROOM_OPTIONS: RoomOption[] = [
    { value: 'green', color: '#BDCCC2', label: 'Grünes Zimmer' },
    { value: 'yellow', color: '#F7E29D', label: 'Gelbes Zimmer' },
    { value: 'peach', color: '#FCE8E1', label: 'Oranges Zimmer' },
    { value: 'blue', color: '#98ACBA', label: 'Blaues Zimmer' },
    { value: 'purple', color: '#CCC8CE', label: 'Lila Zimmer' },
]

// Neutral fill when no room was chosen (slate-200).
export const NEUTRAL_ROOM_COLOR = '#E2E8F0'

/** Resolve a stored room value to its hex color, neutral when unset/unknown. */
export function roomColor(value: string | null): string {
    if (!value) return NEUTRAL_ROOM_COLOR
    return ROOM_OPTIONS.find((r) => r.value === value)?.color ?? NEUTRAL_ROOM_COLOR
}
