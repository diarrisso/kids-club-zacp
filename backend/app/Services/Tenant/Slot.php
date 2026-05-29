<?php

namespace App\Services\Tenant;

use Carbon\CarbonImmutable;

readonly class Slot
{
    public function __construct(
        public CarbonImmutable $starts_at,
        public CarbonImmutable $ends_at,
    ) {}

    public function toArray(): array
    {
        return [
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
        ];
    }
}
