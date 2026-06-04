<?php

namespace App\Support;

/**
 * The five colored treatment rooms of the KidsClub practice. This enum is the
 * authoritative (server-side) source of truth for room → color/label; staff
 * pages receive it via Inertia props (`Room::options()`). The main-app front
 * mirrors it once in `resources/js/lib/rooms.ts`, and the standalone widget
 * bundle inlines its own copy by design — keep all three in sync.
 */
enum Room: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Peach = 'peach';
    case Blue = 'blue';
    case Purple = 'purple';

    public function color(): string
    {
        return match ($this) {
            self::Green => '#BDCCC2',
            self::Yellow => '#F7E29D',
            self::Peach => '#FCE8E1',
            self::Blue => '#98ACBA',
            self::Purple => '#CCC8CE',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Green => 'Grünes Zimmer',
            self::Yellow => 'Gelbes Zimmer',
            self::Peach => 'Oranges Zimmer',
            self::Blue => 'Blaues Zimmer',
            self::Purple => 'Lila Zimmer',
        };
    }

    /** @return list<array{value:string,color:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $r) => ['value' => $r->value, 'color' => $r->color(), 'label' => $r->label()],
            self::cases(),
        );
    }
}
