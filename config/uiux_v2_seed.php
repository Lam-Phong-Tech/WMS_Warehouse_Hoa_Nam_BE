<?php

return [
    // Explicit opt-in for this additive scenario only; never enables the full fixture chain.
    'enabled' => filter_var(env('WMS_UIUX_V2_SEED', false), FILTER_VALIDATE_BOOL),
    'date' => env('WMS_UIUX_V2_SEED_DATE'),
    'actor_id' => env('WMS_UIUX_V2_SEED_ACTOR_ID'),
];
