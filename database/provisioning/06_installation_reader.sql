-- Orbixa provisioning step 6 (06-github-integration, G-A option 1): NOLOGIN
-- owner of integrations.organization_for_installation(bigint), the single
-- reviewed path that routes a GitHub webhook to its organization without a
-- tenant context. Run as orbixa_bootstrap after 01_roles.sql, on every
-- server. Idempotent; needs no secret because the role cannot log in.

\set ON_ERROR_STOP on

SELECT 'CREATE ROLE orbixa_installation_reader NOLOGIN'
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'orbixa_installation_reader') \gexec

ALTER ROLE orbixa_installation_reader NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT NOLOGIN;

-- Nobody but the migrator may become the reader.
REVOKE orbixa_installation_reader FROM orbixa_app;
REVOKE orbixa_installation_reader FROM orbixa_relay;

-- ALTER FUNCTION ... OWNER requires SET ROLE to the new owner: the migrator
-- gets exactly that, without inheriting the reader's privileges.
GRANT orbixa_installation_reader TO orbixa_migrator WITH INHERIT FALSE, SET TRUE;
