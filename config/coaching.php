<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Coaching session settings
    |--------------------------------------------------------------------------
    |
    | max_duration_seconds: Maximum duration for a coaching session in seconds
    | (default 1800 = 30 minutes). Frontend can use this for timer/expiry.
    |
    */

    'max_duration_seconds' => (int) env('COACHING_MAX_DURATION_SECONDS', 1800),

];
