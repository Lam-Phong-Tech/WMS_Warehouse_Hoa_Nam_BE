<?php

return [
    'enabled' => filter_var(env('WMS_PACKING_LABEL_REPORT_SEED', false), FILTER_VALIDATE_BOOL),
    'date' => env('WMS_PACKING_LABEL_REPORT_SEED_DATE'),
    'actor_id' => env('WMS_PACKING_LABEL_REPORT_SEED_ACTOR_ID'),
    'create_sources' => filter_var(env('WMS_PACKING_LABEL_REPORT_CREATE_SOURCES', false), FILTER_VALIDATE_BOOL),
];
