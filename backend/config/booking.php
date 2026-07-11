<?php

return [
    'country' => env('APP_COUNTRY', 'Germany'),
    'bundesland' => env('APP_BUNDESLAND', ''),

    // Anti calendar-squatting: the maximum number of active (pending/confirmed)
    // FUTURE appointments a single parent identity — same email OR same phone —
    // may hold at once. A public booking past this cap is refused with 422.
    // Deliberately generous so a family with several children is never blocked;
    // lower it via env for a busy or targeted practice. Bots reusing one identity
    // are stopped here; bots rotating identities still pay a higher cost.
    'max_active_per_identity' => (int) env('BOOKING_MAX_ACTIVE_PER_IDENTITY', 10),
];
