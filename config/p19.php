<?php

return [
    // Release owner approval is required. This flag is not exposed to Postman.
    // Disabling commands never disables GET reconciliation of existing receipts.
    'commands_enabled' => (bool) env('P19_COMMAND_RECOVERY_ENABLED', false),
];
