<?php

namespace App\Support;

/**
 * Whether a patient actually showed up for their appointment. Stored on
 * `appointments.attendance` (nullable: null = not yet recorded). Staff-only —
 * NEVER mass-assignable from the public widget (set by direct assignment in
 * the cabinet controller, like notes_internal). Mirrors the Room enum pattern.
 */
enum Attendance: string
{
    case Arrived = 'arrived';
    case NoShow = 'no_show';

    /** The German display label (e.g. "Erschienen"). */
    public function label(): string
    {
        return match ($this) {
            self::Arrived => 'Erschienen',
            self::NoShow => 'Nicht erschienen',
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $a) => ['value' => $a->value, 'label' => $a->label()],
            self::cases(),
        );
    }
}
