-- Orbixa provisioning verification. Read-only catalog queries; safe to run at
-- any time as orbixa_bootstrap. Expected results are noted per block.

\set ON_ERROR_STOP on
\pset footer off

\echo '== Roles: rolsuper, rolbypassrls, rolcreatedb, rolcreaterole must be f for migrator/app'
SELECT rolname, rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolreplication, rolinherit, rolcanlogin, rolconnlimit
  FROM pg_roles WHERE rolname LIKE 'orbixa%' ORDER BY 1;

\echo '== Memberships involving orbixa roles (expected: only orbixa_membership_reader -> orbixa_migrator, SET without INHERIT)'
SELECT r.rolname AS role, m.rolname AS member
  FROM pg_auth_members a
  JOIN pg_roles r ON r.oid = a.roleid
  JOIN pg_roles m ON m.oid = a.member
 WHERE r.rolname LIKE 'orbixa%' OR m.rolname LIKE 'orbixa%';

\echo '== Role settings (expected: timeouts only, never app.organization_id)'
SELECT pg_get_userbyid(setrole) AS role,
       coalesce((SELECT d.datname FROM pg_database d WHERE d.oid = s.setdatabase), '(all databases)') AS database,
       setconfig
  FROM pg_db_role_setting s WHERE pg_get_userbyid(setrole) LIKE 'orbixa%';

\echo '== Database privileges for orbixa_app (expected: connect t, temp f, create f)'
SELECT has_database_privilege('orbixa_app', current_database(), 'CONNECT') AS connect,
       has_database_privilege('orbixa_app', current_database(), 'TEMP') AS temp,
       has_database_privilege('orbixa_app', current_database(), 'CREATE') AS create_schema,
       has_schema_privilege('orbixa_app', 'public', 'CREATE') AS create_in_public,
       has_schema_privilege('orbixa_app', 'public', 'USAGE') AS usage_public;

\echo '== Table privileges of orbixa_app (TRUNCATE, REFERENCES, TRIGGER must be f everywhere)'
SELECT c.oid::regclass AS relation,
       has_table_privilege('orbixa_app', c.oid, 'SELECT') AS sel,
       has_table_privilege('orbixa_app', c.oid, 'INSERT') AS ins,
       has_table_privilege('orbixa_app', c.oid, 'UPDATE') AS upd,
       has_table_privilege('orbixa_app', c.oid, 'DELETE') AS del,
       has_table_privilege('orbixa_app', c.oid, 'TRUNCATE') AS trunc,
       has_table_privilege('orbixa_app', c.oid, 'REFERENCES') AS refs,
       has_table_privilege('orbixa_app', c.oid, 'TRIGGER') AS trig
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE c.relkind IN ('r', 'p') AND n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
 ORDER BY 1;

\echo '== RLS state (tables with organization_id must have rls = t and forced = t)'
SELECT n.nspname, c.relname, c.relrowsecurity AS rls, c.relforcerowsecurity AS forced,
       pg_get_userbyid(c.relowner) AS owner,
       EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'organization_id' AND NOT a.attisdropped) AS tenant_scoped
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE c.relkind IN ('r', 'p') AND n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
 ORDER BY 1, 2;

\echo '== Policies (roles must never be {public}; qual and with_check both present)'
SELECT schemaname, tablename, policyname, permissive, roles, cmd, qual, with_check
  FROM pg_policies ORDER BY 1, 2, 3;

\echo '== Ownership (expected: nothing owned by orbixa_app; nothing in public/platform owned by orbixa_bootstrap)'
SELECT n.nspname, c.relname, c.relkind, pg_get_userbyid(c.relowner) AS owner
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast') AND c.relkind IN ('r', 'p', 'S', 'v', 'm')
 ORDER BY 1, 2;
SELECT nspname, pg_get_userbyid(nspowner) AS owner, nspacl FROM pg_namespace WHERE nspname IN ('public', 'platform');
SELECT datname, pg_get_userbyid(datdba) AS owner, datacl, shobj_description(oid, 'pg_database') AS comment
  FROM pg_database WHERE datname IN ('orbixa', 'orbixa_test', 'postgres');

\echo '== Functions in platform (prosecdef must be f)'
SELECT p.oid::regprocedure, pg_get_userbyid(p.proowner) AS owner, p.prosecdef, p.proconfig, p.proacl
  FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'platform';

\echo '== Default ACLs'
SELECT pg_get_userbyid(defaclrole) AS role, defaclnamespace::regnamespace AS schema, defaclobjtype, defaclacl FROM pg_default_acl;
