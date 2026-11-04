-- Orbixa provisioning step 3 (development database ONLY): keep the nine
-- existing Laravel tables and their migration history, moving ownership from
-- orbixa_bootstrap to orbixa_migrator. Non-destructive and reversible
-- (ALTER ... OWNER TO orbixa_bootstrap). Run as orbixa_bootstrap.
--
-- REASSIGN OWNED BY orbixa_bootstrap is deliberately NOT used: it would also
-- hand the databases themselves to the migrator.
-- ALTER TABLE ... OWNER also moves owned sequences (OWNED BY) and indexes.
-- Privileges for orbixa_app are granted afterwards by the migration
-- 2026_11_03_000100_restrict_runtime_privileges_on_global_tables.

\set ON_ERROR_STOP on

SELECT current_database() = 'orbixa' AS on_dev \gset
\if :on_dev
\else
  DO $$ BEGIN RAISE EXCEPTION 'provisioning guard: Step 3 only applies to the development database orbixa'; END $$;
\endif

BEGIN;
ALTER TABLE public.migrations            OWNER TO orbixa_migrator;
ALTER TABLE public.users                 OWNER TO orbixa_migrator;
ALTER TABLE public.password_reset_tokens OWNER TO orbixa_migrator;
ALTER TABLE public.sessions              OWNER TO orbixa_migrator;
ALTER TABLE public.cache                 OWNER TO orbixa_migrator;
ALTER TABLE public.cache_locks           OWNER TO orbixa_migrator;
ALTER TABLE public.jobs                  OWNER TO orbixa_migrator;
ALTER TABLE public.job_batches           OWNER TO orbixa_migrator;
ALTER TABLE public.failed_jobs           OWNER TO orbixa_migrator;

-- Abort if anything in public is still owned by the bootstrap role.
DO $$
DECLARE leftover text;
BEGIN
    SELECT string_agg(c.relname, ', ') INTO leftover
      FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public'
       AND c.relkind IN ('r', 'p', 'S', 'v', 'm')
       AND pg_get_userbyid(c.relowner) = 'orbixa_bootstrap';
    IF leftover IS NOT NULL THEN
        RAISE EXCEPTION 'Objects still owned by orbixa_bootstrap: %', leftover;
    END IF;
END
$$;
COMMIT;
