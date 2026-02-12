<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mock exam settings
    |--------------------------------------------------------------------------
    |
    | questions_per_exam: Number of questions per exam (default 25).
    | duration_seconds: Time limit per exam in seconds (default 1800 = 30 minutes).
    |
    */

    'questions_per_exam' => (int) env('EXAM_QUESTIONS_PER_EXAM', 25),
    'duration_seconds' => (int) env('EXAM_DURATION_SECONDS', 1800),

];
