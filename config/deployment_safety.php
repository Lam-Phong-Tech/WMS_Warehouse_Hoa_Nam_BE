<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Protected deployment database
    |--------------------------------------------------------------------------
    |
    | Green/Customer set this explicitly in .env. Keep test databases open for
    | disposable-schema tests, while real deployed databases fail closed against
    | destructive Artisan commands and an unreviewed full seed.
    |
    */
    'protect_database' => filter_var(
        env('WMS_PROTECT_DEPLOYMENT_DATABASE', env('APP_ENV') !== 'testing'),
        FILTER_VALIDATE_BOOL,
    ),

    // Break-glass only: set for one reviewed seed command, never persist it.
    'allow_protected_database_seed' => env('WMS_ALLOW_PROTECTED_DATABASE_SEED') === 'CONFIRM_SEED_WRITE',
];
