<?php

return [
    /*
    | Inactivity threshold, in minutes, after which a signed-in staff user is
    | automatically logged out client-side (IdleTimeoutMonitor.vue). A warning
    | modal appears 1 minute before. This is a client-side idle guard, distinct
    | from SESSION_LIFETIME (the server-side session TTL).
    |
    | Recommended:
    |   prod      : 15 (standard, anti session-theft)
    |   staging   : 5  (fast test)
    |   local/dev : 1  (fast iteration — modal skipped, silent logout)
    */
    'seuil_minutes' => env('IDLE_TIMEOUT_MIN', 15),
];
