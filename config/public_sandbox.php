<?php

// CLI-only opt-in; never exposed by /public/config and never enables a bypass
// in Quote, authentication, publication, consent or rate-limit validation.
return [
    'enabled' => (bool) env('PUBLIC_QUOTE_SANDBOX_ENABLED', false),
    'build_sha' => env('APP_BUILD_SHA'),
];
