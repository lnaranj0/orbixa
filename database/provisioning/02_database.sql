-- Orbixa provisioning step 2: per-database privileges. Run as orbixa_bootstrap,
-- connected to the target database, with -v dbname=<orbixa|orbixa_test>.
-- Idempotent. The database itself stays owned by orbixa_bootstrap, so the
-- migrator can never DROP or ALTER DATABASE.

\set ON_ERROR_STOP on

SELECT current_database() = :'dbname' AS on_target \gset
\if :on_target
\else
  DO $$ BEGIN RAISE EXCEPTION 'provisioning guard: Connected database does not match -v dbname'; END $$;
\endif

REVOKE ALL ON DATABASE :"dbname" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"dbname" TO orbixa_migrator, orbixa_app, orbixa_relay;
-- Migrator creates module schemas (platform, audit, ...). No TEMP for anyone.
GRANT CREATE ON DATABASE :"dbname" TO orbixa_migrator;

-- Nobody but the bootstrap superuser needs the maintenance database.
REVOKE CONNECT ON DATABASE postgres FROM PUBLIC;

ALTER SCHEMA public OWNER TO orbixa_migrator;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO orbixa_app;

-- Tables get NO default privileges: each migration grants explicitly.
-- Functions created by the migrator are not executable by PUBLIC by default.
ALTER DEFAULT PRIVILEGES FOR ROLE orbixa_migrator REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

ALTER ROLE orbixa_app IN DATABASE :"dbname" SET statement_timeout = '30s';
ALTER ROLE orbixa_app IN DATABASE :"dbname" SET idle_in_transaction_session_timeout = '30s';
ALTER ROLE orbixa_app IN DATABASE :"dbname" SET lock_timeout = '5s';
ALTER ROLE orbixa_relay IN DATABASE :"dbname" SET statement_timeout = '30s';
ALTER ROLE orbixa_relay IN DATABASE :"dbname" SET idle_in_transaction_session_timeout = '30s';
ALTER ROLE orbixa_relay IN DATABASE :"dbname" SET lock_timeout = '5s';
ALTER ROLE orbixa_migrator IN DATABASE :"dbname" SET lock_timeout = '5s';
ALTER ROLE orbixa_migrator IN DATABASE :"dbname" SET idle_in_transaction_session_timeout = '5min';
