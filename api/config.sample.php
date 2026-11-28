<?php
/**
 * Configuration file for chudcheats.xyz
 * Rename or copy to config.php to activate custom settings
 */
return [
    // Database settings (optional - defaults to SQLite automatically if false)
    'use_mysql' => false,
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'cpaneluser_chudcheats',
    'db_user' => 'cpaneluser_dbuser',
    'db_pass' => 'YourStrongPasswordHere',
    'db_charset' => 'utf8mb4',

    // Square Payments Configuration
    'square' => [
        'environment' => 'sandbox', // 'sandbox' for testing, 'production' for live payments
        'application_id' => 'sandbox-sq0idb-YOUR_SQUARE_APP_ID',
        'access_token' => 'EAAA_YOUR_SQUARE_ACCESS_TOKEN',
        'location_id' => 'YOUR_SQUARE_LOCATION_ID',
        'currency' => 'USD',
        'webhook_signature_key' => '' // optional Square webhook HMAC key
    ],

    // Cloudflare Turnstile Verification
    'turnstile' => [
        'enabled' => false,
        'site_key' => 'YOUR_CLOUDFLARE_TURNSTILE_SITE_KEY',
        'secret_key' => 'YOUR_CLOUDFLARE_TURNSTILE_SECRET_KEY'
    ],

    // Loader Distribution (optional - serves data/loader/orbit-loader.exe if present, else download_url or Discord)
    'loader' => [
        'file_path' => __DIR__ . '/../data/loader/orbit-loader.exe',
        'download_url' => ''
    ],

    // Transactional Mail Sender (password reset emails)
    'mail' => [
        'from_email' => 'noreply@chudcheats.xyz'
    ]
];
