<?php

return [
    // Browser-side WebSocket target. Empty = derive from the page's own host (recommended behind Nginx).
    'realtime' => [
        'host' => env('REVERB_PUBLIC_HOST'),
        'port' => env('REVERB_PUBLIC_PORT'),
        'scheme' => env('REVERB_PUBLIC_SCHEME'),
        'local_port' => (int) env('REVERB_SERVER_PORT', 8080),
    ],

    // QZ Tray signing material (PRD 7.5). Keep outside the web root.
    'qz' => [
        'certificate' => env('QZ_CERTIFICATE_PATH', storage_path('app/qz/digital-certificate.txt')),
        'private_key' => env('QZ_PRIVATE_KEY_PATH', storage_path('app/qz/private-key.pem')),
    ],
];
