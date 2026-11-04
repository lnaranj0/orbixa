<?php

/*
 * Development credentials for the role separation (D1–D5). Run once from the
 * project root:  php scripts/db/init-dev-credentials.php
 *
 * - Backs up .env to .env.backup-<UTC timestamp> (gitignored, mode 600).
 * - Moves the bootstrap password (POSTGRES_ADMIN_PASSWORD) from .env into
 *   .secrets/pg_bootstrap_password, the Compose secret. The existing volume
 *   keeps its current superuser password, so the value is moved, not changed.
 * - Creates .secrets/redis_password if missing.
 * - Points Laravel at orbixa_app (runtime) and orbixa_migrator (migrations)
 *   with fresh random passwords, Redis for cache and queues, encrypted
 *   sessions, and removes duplicate keys.
 *
 * Never prints a secret: only the names of the keys it changed.
 */

$root = dirname(__DIR__, 2);
chdir($root);
umask(0077);

function fail(string $message): never
{
    fwrite(STDERR, "init-dev-credentials: {$message}\n");
    exit(1);
}

function secret(): string
{
    return substr(strtr(base64_encode(random_bytes(36)), ['+' => '', '/' => '', '=' => '']), 0, 32);
}

if (! is_file('.env')) {
    fail('.env not found');
}

$lines = file('.env', FILE_IGNORE_NEW_LINES);
$values = [];
foreach ($lines as $line) {
    if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m) === 1 && ! array_key_exists($m[1], $values)) {
        $values[$m[1]] = $m[2];
    }
}

// 1. Backup.
$backup = '.env.backup-'.gmdate('Ymd\THis\Z');
copy('.env', $backup) || fail('could not back up .env');
chmod($backup, 0600);
echo "backup  {$backup}\n";

// 2. Compose secrets.
is_dir('.secrets') || mkdir('.secrets', 0700);
chmod('.secrets', 0700);

if (! is_file('.secrets/pg_bootstrap_password')) {
    $bootstrap = trim((string) ($values['POSTGRES_ADMIN_PASSWORD'] ?? ''), "\"'");
    $bootstrap !== '' || fail('POSTGRES_ADMIN_PASSWORD is missing from .env; create .secrets/pg_bootstrap_password manually');
    file_put_contents('.secrets/pg_bootstrap_password', $bootstrap);
    echo "created .secrets/pg_bootstrap_password (moved from .env)\n";
} else {
    echo "keep    .secrets/pg_bootstrap_password\n";
}

if (! is_file('.secrets/redis_password')) {
    file_put_contents('.secrets/redis_password', secret());
    echo "created .secrets/redis_password\n";
} else {
    echo "keep    .secrets/redis_password\n";
}

// 3. Laravel settings.
$alreadyMigrated = ($values['DB_USERNAME'] ?? '') === 'orbixa_app' && ($values['DB_MIGRATOR_PASSWORD'] ?? '') !== '';

$set = [
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '5433',
    'DB_DATABASE' => 'orbixa',
    'DB_USERNAME' => 'orbixa_app',
    'DB_PASSWORD' => $alreadyMigrated ? $values['DB_PASSWORD'] : secret(),
    'DB_MIGRATOR_USERNAME' => 'orbixa_migrator',
    'DB_MIGRATOR_PASSWORD' => $alreadyMigrated ? $values['DB_MIGRATOR_PASSWORD'] : secret(),
    'DB_RELAY_USERNAME' => 'orbixa_relay',
    'DB_RELAY_PASSWORD' => ($values['DB_RELAY_PASSWORD'] ?? '') !== '' ? $values['DB_RELAY_PASSWORD'] : secret(),
    'DB_ALLOW_DESTRUCTIVE_COMMANDS' => 'false',
    'CACHE_STORE' => 'redis',
    'QUEUE_CONNECTION' => 'redis',
    'SESSION_ENCRYPT' => 'true',
    'REDIS_CLIENT' => 'predis',
    'REDIS_HOST' => '127.0.0.1',
    'REDIS_PORT' => '6380',
    'REDIS_PASSWORD' => trim((string) file_get_contents('.secrets/redis_password')),
];
$remove = ['POSTGRES_ADMIN_PASSWORD', 'DB_URL'];

$output = [];
$seen = [];
foreach ($lines as $line) {
    if (preg_match('/^([A-Z0-9_]+)=/', $line, $m) === 1) {
        $key = $m[1];
        if (in_array($key, $remove, true) || isset($seen[$key])) {
            continue; // drop removed keys and duplicates (keeps the first occurrence)
        }
        $seen[$key] = true;
        if (array_key_exists($key, $set)) {
            $line = "{$key}={$set[$key]}";
        }
    }
    $output[] = $line;
}
foreach ($set as $key => $value) {
    if (! isset($seen[$key])) {
        $output[] = "{$key}={$value}";
    }
}

file_put_contents('.env.tmp', implode("\n", $output)."\n");
chmod('.env.tmp', 0600);
rename('.env.tmp', '.env') || fail('could not write .env');

echo 'updated .env: '.implode(', ', array_keys($set)).'; removed '.implode(', ', $remove)."\n";
echo $alreadyMigrated ? "kept existing DB_PASSWORD / DB_MIGRATOR_PASSWORD\n" : "generated new DB_PASSWORD / DB_MIGRATOR_PASSWORD\n";
