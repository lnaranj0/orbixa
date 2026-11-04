-- Orbixa provisioning step 1: cluster-wide roles. Run as orbixa_bootstrap.
-- Idempotent. Only SCRAM-SHA-256 verifiers reach the server
-- (ORBIXA_MIGRATOR_PASSWORD_VERIFIER / ORBIXA_APP_PASSWORD_VERIFIER, computed
-- client-side by scripts/db/scram-verifier.php and read via \getenv), so the
-- plaintext never appears in this file, server logs, pg_stat_activity, shell
-- history or the process list.
-- Existing roles keep their password; attributes are re-asserted every run.

\set ON_ERROR_STOP on

\getenv migrator_password ORBIXA_MIGRATOR_PASSWORD_VERIFIER
\getenv app_password ORBIXA_APP_PASSWORD_VERIFIER
\getenv relay_password ORBIXA_RELAY_PASSWORD_VERIFIER

\if :{?migrator_password}
\else
  DO $$ BEGIN RAISE EXCEPTION 'provisioning guard: ORBIXA_MIGRATOR_PASSWORD_VERIFIER is not set'; END $$;
\endif
\if :{?app_password}
\else
  DO $$ BEGIN RAISE EXCEPTION 'provisioning guard: ORBIXA_APP_PASSWORD_VERIFIER is not set'; END $$;
\endif
\if :{?relay_password}
\else
  DO $$ BEGIN RAISE EXCEPTION 'provisioning guard: ORBIXA_RELAY_PASSWORD_VERIFIER is not set'; END $$;
\endif

SELECT :'migrator_password' LIKE 'SCRAM-SHA-256$%' AND :'app_password' LIKE 'SCRAM-SHA-256$%'
   AND :'relay_password' LIKE 'SCRAM-SHA-256$%' AS verifiers_ok \gset
\if :verifiers_ok
\else
  DO $$ BEGIN RAISE EXCEPTION 'provisioning guard: role passwords must be SCRAM-SHA-256 verifiers, never plaintext'; END $$;
\endif

-- Defense in depth: keep this session's statements out of the server log.
SET log_statement = 'none';
SET log_min_error_statement = 'panic';

SELECT format('CREATE ROLE orbixa_migrator LOGIN PASSWORD %L', :'migrator_password')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'orbixa_migrator') \gexec

SELECT format('CREATE ROLE orbixa_app LOGIN PASSWORD %L', :'app_password')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'orbixa_app') \gexec

-- Outbox relay (option A): no BYPASSRLS; policies + column grants limit it
-- to pending rows of events.domain_outbox.
SELECT format('CREATE ROLE orbixa_relay LOGIN PASSWORD %L', :'relay_password')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'orbixa_relay') \gexec

\unset migrator_password
\unset app_password
\unset relay_password

ALTER ROLE orbixa_migrator NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT LOGIN CONNECTION LIMIT 5;
ALTER ROLE orbixa_app      NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT LOGIN CONNECTION LIMIT 100;
ALTER ROLE orbixa_relay    NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT LOGIN CONNECTION LIMIT 5;

-- No membership in either direction (prevents SET ROLE escalation).
REVOKE orbixa_migrator FROM orbixa_app;
REVOKE orbixa_app FROM orbixa_migrator;
REVOKE orbixa_migrator FROM orbixa_relay;
REVOKE orbixa_app FROM orbixa_relay;
