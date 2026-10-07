<?php

return [
    // Populate only with separately approved, archived policy versions. No production defaults.
    'current' => ['en' => ['terms' => env('REGISTRATION_TERMS_EN_VERSION'), 'privacy' => env('REGISTRATION_PRIVACY_EN_VERSION')]],
    // Explicitly provision an immutable archive disk before versioned registration is enabled.
    'archive_disk' => env('REGISTRATION_POLICY_ARCHIVE_DISK'),
    'archive_max_bytes' => 4194304,
    // Existing web/API clients must be coordinated before enabling strict version transport.
    'require_versions' => false,
];
