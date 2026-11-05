#!/usr/bin/env bash
# Orbixa database provisioning. Runs the SQL in database/provisioning/ through
# `docker compose exec` as orbixa_bootstrap. Every step is explicit, so each
# sensitive operation can be reviewed and approved on its own.
#
# Usage: scripts/db/provision.sh <dev|test> <roles|database|transfer|sentinel|reader|installation-reader|verify>
#
#   roles     01_roles.sql            create/re-assert orbixa_migrator, orbixa_app
#   database  02_database.sql         database/schema privileges and role settings
#   transfer  03_transfer_...sql      dev only: move the 9 existing tables to the migrator
#   sentinel  04_test_sentinel.sql    test only: mark orbixa_test as ephemeral
#   reader    05_membership_reader.sql NOLOGIN owner of membership functions (01)
#   installation-reader  06_installation_reader.sql NOLOGIN owner of the GitHub webhook router (06)
#   verify    90_verify.sql           read-only catalog report
#
# Passwords for the roles step are read from ORBIXA_MIGRATOR_PASSWORD,
# ORBIXA_APP_PASSWORD and ORBIXA_RELAY_PASSWORD, or prompted without echo. Only their SCRAM-SHA-256
# verifiers (scripts/db/scram-verifier.php) are sent to PostgreSQL, forwarded
# by name (docker compose exec -e NAME), never as command-line values.
#
# CI: set ORBIXA_PROVISION_CONTAINER=<container> to run psql through
# `docker exec` in a plain container instead of a Compose service.

set -euo pipefail

usage() {
    sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'
    exit 64
}

die() {
    echo "provision: $*" >&2
    exit 1
}

[[ $# -eq 2 ]] || usage
target=$1
step=$2

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
sql_dir="$root/database/provisioning"
compose=(docker compose --project-directory "$root")

case "$target" in
    dev)  service=postgres;      database=orbixa ;;
    test) service=postgres-test; database=orbixa_test; compose+=(--profile test) ;;
    *)    usage ;;
esac

run_sql() {
    local file=$1
    shift
    local runner=("${compose[@]}" exec -T "$@" "$service")
    if [[ -n "${ORBIXA_PROVISION_CONTAINER:-}" ]]; then
        runner=(docker exec -i "$@" "$ORBIXA_PROVISION_CONTAINER")
    fi
    "${runner[@]}" \
        psql -X -v ON_ERROR_STOP=1 -U orbixa_bootstrap -d "$database" -v dbname="$database" -f - \
        < "$sql_dir/$file"
}

read_secret() {
    local name=$1 value
    if [[ -z "${!name:-}" ]]; then
        read -r -s -p "$name: " value
        echo >&2
        [[ -n "$value" ]] || die "$name must not be empty"
        export "$name=$value"
    fi
}

case "$step" in
    roles)
        read_secret ORBIXA_MIGRATOR_PASSWORD
        read_secret ORBIXA_APP_PASSWORD
        read_secret ORBIXA_RELAY_PASSWORD
        ORBIXA_MIGRATOR_PASSWORD_VERIFIER=$(printf '%s' "$ORBIXA_MIGRATOR_PASSWORD" | php "$root/scripts/db/scram-verifier.php")
        ORBIXA_APP_PASSWORD_VERIFIER=$(printf '%s' "$ORBIXA_APP_PASSWORD" | php "$root/scripts/db/scram-verifier.php")
        ORBIXA_RELAY_PASSWORD_VERIFIER=$(printf '%s' "$ORBIXA_RELAY_PASSWORD" | php "$root/scripts/db/scram-verifier.php")
        export ORBIXA_MIGRATOR_PASSWORD_VERIFIER ORBIXA_APP_PASSWORD_VERIFIER ORBIXA_RELAY_PASSWORD_VERIFIER
        unset ORBIXA_MIGRATOR_PASSWORD ORBIXA_APP_PASSWORD ORBIXA_RELAY_PASSWORD
        run_sql 01_roles.sql -e ORBIXA_MIGRATOR_PASSWORD_VERIFIER -e ORBIXA_APP_PASSWORD_VERIFIER -e ORBIXA_RELAY_PASSWORD_VERIFIER
        ;;
    database)
        run_sql 02_database.sql
        ;;
    transfer)
        [[ "$target" == dev ]] || die "transfer only applies to the development database"
        run_sql 03_transfer_existing_ownership.sql
        ;;
    sentinel)
        [[ "$target" == test ]] || die "the ephemeral sentinel may only be set on the test server"
        run_sql 04_test_sentinel.sql
        ;;
    reader)
        run_sql 05_membership_reader.sql
        ;;
    installation-reader)
        run_sql 06_installation_reader.sql
        ;;
    verify)
        run_sql 90_verify.sql
        ;;
    *)
        usage
        ;;
esac
