-- Orbixa provisioning step 4 (test server ONLY): mark orbixa_test as the
-- ephemeral test database. EphemeralTestDatabaseGuard and the Security test
-- suite refuse to run against any database without this exact comment.

\set ON_ERROR_STOP on

SELECT current_database() = 'orbixa_test' AS on_test \gset
\if :on_test
\else
  DO $$ BEGIN RAISE EXCEPTION 'provisioning guard: The sentinel may only be set on orbixa_test'; END $$;
\endif

COMMENT ON DATABASE orbixa_test IS 'orbixa:ephemeral-test';
