<?php

/*
 * Reads a password from stdin and prints a PostgreSQL SCRAM-SHA-256 verifier
 * (RFC 5802 / RFC 7677), so CREATE ROLE never transmits the plaintext:
 *
 *   SCRAM-SHA-256$<iterations>:<salt>$<StoredKey>:<ServerKey>
 *
 * PostgreSQL applies SASLprep to the password; restricting input to
 * printable ASCII keeps the client-side result identical to the server's.
 */

$password = rtrim((string) stream_get_contents(STDIN), "\r\n");

if ($password === '' || preg_match('/^[\x21-\x7E]+$/', $password) !== 1) {
    fwrite(STDERR, "Password must be non-empty printable ASCII without spaces.\n");
    exit(1);
}

$iterations = 4096;
$salt = random_bytes(16);
$salted = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
$clientKey = hash_hmac('sha256', 'Client Key', $salted, true);
$storedKey = hash('sha256', $clientKey, true);
$serverKey = hash_hmac('sha256', 'Server Key', $salted, true);

echo sprintf(
    'SCRAM-SHA-256$%d:%s$%s:%s',
    $iterations,
    base64_encode($salt),
    base64_encode($storedKey),
    base64_encode($serverKey),
);
