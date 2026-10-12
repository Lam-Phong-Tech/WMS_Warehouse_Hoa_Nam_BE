<?php

return [
    // Independent rollout gate. A source commit or a passing test does not enable DEV/production.
    'enabled' => (bool) env('P12_MIXED_DOCUMENTS_ENABLED', false),
];
