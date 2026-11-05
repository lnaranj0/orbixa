<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GitHub App (06-github-integration, G10)
    |--------------------------------------------------------------------------
    |
    | driver: "disabled" (default, every call fails closed), "fake" (in-memory,
    | development and tests; refused in production) or "http" (real App).
    |
    | Only file paths live here, never secret values, so `config:cache` and
    | config dumps cannot hold a secret. The files go in .secrets/ (mode 600,
    | gitignored) or are mounted by the environment's secret manager.
    |
    */

    'driver' => env('GITHUB_DRIVER', 'disabled'),

    'app_id' => env('GITHUB_APP_ID'),

    'client_id' => env('GITHUB_APP_CLIENT_ID'),

    // https://github.com/apps/{slug}: where an installation starts. The App
    // must request user authorization (OAuth) during installation and use
    // /integrations/github/callback as its setup URL (G-C).
    'app_slug' => env('GITHUB_APP_SLUG'),

    'timeout' => (int) env('GITHUB_TIMEOUT', 10),

    'secrets' => [
        'private_key' => env('GITHUB_APP_PRIVATE_KEY_PATH', base_path('.secrets/github-app.pem')),
        'webhook' => env('GITHUB_WEBHOOK_SECRET_PATH', base_path('.secrets/github-webhook')),
        'webhook_previous' => env('GITHUB_WEBHOOK_PREVIOUS_SECRET_PATH', base_path('.secrets/github-webhook.previous')),
        'oauth_client' => env('GITHUB_OAUTH_CLIENT_SECRET_PATH', base_path('.secrets/github-oauth-client')),
    ],

    // GitHub caps webhook payloads at 25 MB; larger bodies get 413 unread.
    'webhook_max_bytes' => (int) env('GITHUB_WEBHOOK_MAX_BYTES', 25 * 1024 * 1024),

    // Per source IP; GitHub delivers from a small set of addresses.
    'webhook_rate_per_minute' => (int) env('GITHUB_WEBHOOK_RATE_PER_MINUTE', 600),

    // Setup start + callback, per user per minute and per organization per hour.
    'setup_per_minute' => (int) env('GITHUB_SETUP_PER_MINUTE', 5),
    'setup_per_hour' => (int) env('GITHUB_SETUP_PER_HOUR', 20),

    // Installation tokens are cached encrypted (APP_KEY) in this store.
    'token_store' => env('GITHUB_TOKEN_STORE', 'redis'),

];
