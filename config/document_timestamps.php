<?php

return [
    // Storage convention, NOT the display/app/PG session timezone. Existing Hoa
    // Nam naive document values are Vietnam wall-clock. UTC is only for a
    // separately verified UTC-origin dataset (including old isolated fixtures).
    'legacy_naive_timezone' => env('DOCUMENT_LEGACY_NAIVE_TIMEZONE', 'Asia/Ho_Chi_Minh'),
];
