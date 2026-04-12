<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Allows the Aleph React frontend (Vite dev server / production build)
    | to communicate with this Laravel API without browser CORS errors.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',   // Vite dev server
        'http://localhost:3000',   // Alt dev port
        'http://localhost:8082',   // Expo web preview
        'http://localhost:8081',   // Expo web preview
        'http://localhost:8083',   // Expo web preview (alt port)
        'http://localhost',        // XAMPP production
        'http://127.0.0.1:5173',
        'http://127.0.0.1:8082',
        'http://127.0.0.1:8081',
        'http://127.0.0.1',
    ],

    'allowed_origins_patterns' => [
        '#^http://192\.168\.\d+\.\d+(:\d+)?$#',  // LAN local (celular en misma red)
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
