<?php

namespace App\Support;

enum WaitlistStatus: string
{
    case Pending = 'pending';
    case Contacted = 'contacted';
    case Booked = 'booked';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ausstehend',
            self::Contacted => 'Kontaktiert',
            self::Booked => 'Gebucht',
            self::Cancelled => 'Storniert',
        };
    }

    /** @return array<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
