<?php

return [
    // PRD 8: TOTP is mandatory for owner accounts.
    'require_owner_2fa' => env('POS_REQUIRE_OWNER_2FA', true),

    // QZ Tray signing material (PRD 7.5). Keep outside the web root.
    'qz' => [
        'certificate' => env('QZ_CERTIFICATE_PATH', storage_path('app/qz/digital-certificate.txt')),
        'private_key' => env('QZ_PRIVATE_KEY_PATH', storage_path('app/qz/private-key.pem')),
    ],
];
