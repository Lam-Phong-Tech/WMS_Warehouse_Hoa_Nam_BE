<?php

return [
    // Source candidate. Enabling this is a separate security/release decision.
    'security_commands_enabled' => (bool) env('P11_SECURITY_COMMANDS_ENABLED', false),
    'reconciliation_assurance_seconds' => 300,
];
