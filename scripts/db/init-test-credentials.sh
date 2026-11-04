#!/usr/bin/env bash
# Creates throwaway credentials for the EPHEMERAL test server (postgres-test,
# tmpfs) only. Never touches development secrets, never overwrites existing
# files and never prints a secret.
#
#   .secrets/pg_test_bootstrap_password   superuser of postgres-test (Compose secret)
#   .env.testing                          APP_KEY + orbixa_app / orbixa_migrator passwords
#
# Usage: scripts/db/init-test-credentials.sh

set -euo pipefail
umask 077

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root"

random_secret() {
    openssl rand -base64 48 | tr -d '/+=\n' | cut -c1-32
}

mkdir -p .secrets
chmod 700 .secrets

if [[ -e .secrets/pg_test_bootstrap_password ]]; then
    echo "keep    .secrets/pg_test_bootstrap_password (already exists)"
else
    random_secret > .secrets/pg_test_bootstrap_password
    echo "created .secrets/pg_test_bootstrap_password"
fi

if [[ -e .env.testing ]]; then
    echo "keep    .env.testing (already exists)"
    # Add credentials introduced after the file was created (never rotates existing ones).
    if ! grep -q '^DB_RELAY_PASSWORD=.' .env.testing; then
        relay_password=$(random_secret)
        grep -Ev '^DB_RELAY_(USERNAME|PASSWORD)=' .env.testing > .env.testing.tmp
        printf 'DB_RELAY_USERNAME=orbixa_relay\nDB_RELAY_PASSWORD=%s\n' "$relay_password" >> .env.testing.tmp
        mv .env.testing.tmp .env.testing
        echo "added   DB_RELAY_USERNAME / DB_RELAY_PASSWORD to .env.testing"
    fi
else
    app_key="base64:$(openssl rand -base64 32)"
    app_password=$(random_secret)
    migrator_password=$(random_secret)
    relay_password=$(random_secret)
    sed -e "s|^APP_KEY=.*|APP_KEY=${app_key}|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${app_password}|" \
        -e "s|^DB_MIGRATOR_PASSWORD=.*|DB_MIGRATOR_PASSWORD=${migrator_password}|" \
        -e "s|^DB_RELAY_PASSWORD=.*|DB_RELAY_PASSWORD=${relay_password}|" \
        .env.testing.example > .env.testing
    echo "created .env.testing"
fi

for generated in .secrets/pg_test_bootstrap_password .env.testing; do
    git check-ignore -q "$generated" \
        || { echo "init-test-credentials: $generated is not gitignored" >&2; exit 1; }
done
