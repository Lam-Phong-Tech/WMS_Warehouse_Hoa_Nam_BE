<?php

use App\Support\BuildIdentity;

// CLI-only opt-in; never exposed by /public/config and never enables a bypass
// in Quote, authentication, publication, consent or rate-limit validation.
return [
    'enabled' => (bool) env('PUBLIC_QUOTE_SANDBOX_ENABLED', false),
    'build_sha' => BuildIdentity::resolve(
        base_path('.ci-source-sha'),
        env('APP_BUILD_SHA'),
        env('APP_BUILD_REF'),
        env('APP_BUILD_TIME'),
    )['sha'],
];
