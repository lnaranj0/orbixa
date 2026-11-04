-- Orbixa provisioning step 5 (01-identity-tenancy, option A): NOLOGIN owner
-- of the narrow SECURITY DEFINER membership functions. Run as
-- orbixa_bootstrap after 01_roles.sql, on every server. Idempotent; needs no
-- secret because the role cannot log in.

\set ON_ERROR_STOP on

SELECT 'CREATE ROLE orbixa_membership_reader NOLOGIN'
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'orbixa_membership_reader') \gexec

ALTER ROLE orbixa_membership_reader NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT NOLOGIN;

-- Nobody but the migrator may become the reader.
REVOKE orbixa_membership_reader FROM orbixa_app;
REVOKE orbixa_membership_reader FROM orbixa_relay;

-- ALTER FUNCTION ... OWNER requires SET ROLE to the new owner: the migrator
-- gets exactly that, without inheriting the reader's privileges.
GRANT orbixa_membership_reader TO orbixa_migrator WITH INHERIT FALSE, SET TRUE;
