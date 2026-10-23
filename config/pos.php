<?php

return [
    // Destructive artisan commands (migrate:fresh, db:wipe ...) only run on a database whose name ends in
    // "_testing", or when this is switched on for one command: ALLOW_DESTRUCTIVE_DB=1 php artisan migrate:fresh
    'allow_destructive_db' => (bool) env('ALLOW_DESTRUCTIVE_DB', false),

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
